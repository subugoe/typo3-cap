/** Prepare navigation cookies and separate proofs for groups of AJAX requests. */
(function () {
    'use strict';

    var script = document.getElementById('typo3-cap-handler');
    if (!script || window.Typo3Cap) return;
    var config = JSON.parse(script.getAttribute('data-config'));

    var cookieName = config.cookieName;
    var retryCookie = cookieName + '_retry';
    var tokenTtlMs = config.tokenTtlMs;
    var prepared = null;
    var solving = null;
    var renewalTimer = null;
    var suspended = false;
    var navigating = false;
    var resumingNavigation = false;
    var activeCap = null;
    var resumingForms = new WeakSet();

    function readCookie(name) {
        var prefix = name + '=';
        var pairs = document.cookie.split(';');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].trim();
            if (pair.indexOf(prefix) === 0) {
                try { return decodeURIComponent(pair.slice(prefix.length)); } catch (_) { return ''; }
            }
        }
        return '';
    }

    function writeCookie(name, value, seconds) {
        document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=' + seconds +
            '; Path=/; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function readyToken() {
        // Never restore consumed tokens from storage or cached solve results.
        if (prepared && prepared.expires > Date.now() + 1000 && readCookie(cookieName) === prepared.token) {
            return prepared.token;
        }
        return null;
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function waitForLibrary() {
        var deadline = Date.now() + 10000;
        function check() {
            if (typeof window.Cap === 'function') return Promise.resolve();
            if (Date.now() >= deadline) return Promise.reject(new Error('Cap library did not load'));
            return delay(100).then(check);
        }
        return check();
    }

    function scheduleRenewal(ms) {
        clearTimeout(renewalTimer);
        if (config.challengePage || suspended || document.hidden) return;
        renewalTimer = setTimeout(function () {
            prepared = null;
            background();
        }, ms);
    }

    function discardInstance() {
        var old = activeCap;
        activeCap = null;
        if (old) {
            old.reset();
            if (old.widget && typeof old.widget.cleanup === 'function') old.widget.cleanup();
        }
    }

    function solveFresh(attempt) {
        discardInstance();
        var cap = new window.Cap({
            apiEndpoint: config.apiEndpoint,
            'data-cap-worker-count': String(Math.min(2, navigator.hardwareConcurrency || 2))
        });
        activeCap = cap;
        cap.addEventListener('reset', function () {
            if (activeCap !== cap) return;
            if (prepared && readCookie(cookieName) === prepared.token) writeCookie(cookieName, '', 0);
            prepared = null;
            scheduleRenewal(0);
        });
        var timeout;
        return Promise.race([
            Promise.resolve().then(function () { return cap.solve(); }),
            new Promise(function (_, reject) {
                timeout = setTimeout(function () { reject(new Error('Challenge timed out')); }, 60000);
            })
        ]).then(function (solution) {
            clearTimeout(timeout);
            if (!solution || typeof solution.token !== 'string' || !solution.token) {
                throw new Error('Cap returned no token');
            }
            prepared = { token: solution.token, expires: Date.now() + tokenTtlMs };
            writeCookie(cookieName, solution.token, Math.floor(tokenTtlMs / 1000));
            if (readCookie(cookieName) !== solution.token) throw new Error('Cookies are unavailable');
            scheduleRenewal(Math.max(1000, tokenTtlMs - 10000));
            return solution.token;
        }).catch(function (error) {
            clearTimeout(timeout);
            discardInstance();
            prepared = null;
            if (attempt < 2 && !suspended) {
                return delay(500 * (attempt + 1)).then(function () { return solveFresh(attempt + 1); });
            }
            throw error;
        });
    }

    function prepare() {
        var token = readyToken();
        if (token) return Promise.resolve(token);
        if (solving) return solving;
        solving = waitForLibrary().then(function () { return solveFresh(0); }).finally(function () {
            solving = null;
        });
        return solving;
    }

    function background() {
        if (suspended || document.hidden) return;
        prepare().catch(function (error) {
            console.warn('[Typo3Cap] Background challenge failed:', error);
            scheduleRenewal(15000);
        });
    }

    function resumeBackground() {
        background();
        if (readyToken()) scheduleRenewal(Math.max(0, prepared.expires - Date.now() - 10000));
    }

    function showError(retry) {
        var spinner = document.getElementById('typo3-cap-spinner');
        if (spinner) spinner.hidden = true;
        var message = document.getElementById('typo3-cap-error');
        if (!message) {
            message = document.createElement('p');
            message.id = 'typo3-cap-error';
            message.setAttribute('role', 'alert');
            document.body.appendChild(message);
        }
        message.textContent = 'We could not prepare the next page. ';
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Try again';
        button.addEventListener('click', function () {
            message.remove();
            if (spinner) spinner.hidden = false;
            writeCookie(retryCookie, '', 0);
            retry();
        });
        message.appendChild(button);
    }

    function navigate(action) {
        if (navigating) return;
        navigating = true;
        prepare().then(function () {
            navigating = false;
            action();
        }).catch(function (error) {
            navigating = false;
            console.warn('[Typo3Cap] Navigation challenge failed:', error);
            showError(function () { navigate(action); });
        });
    }

    function sameOriginUrl(href) {
        try {
            var url = new URL(href, document.baseURI);
            return url.origin === window.location.origin && /^https?:$/.test(url.protocol) ? url : null;
        } catch (_) { return null; }
    }

    function targetsCurrentPage(element, attribute) {
        var target = element.getAttribute(attribute) || '';
        if (!target) {
            var base = document.querySelector('base[target]');
            target = base ? base.getAttribute('target') : '';
        }
        return !target || target.toLowerCase() === '_self';
    }

    function setupRequests() {
        var nativeFetch = window.fetch.bind(window);
        var checks = new Map();
        var batches = new Map();
        var queue = Promise.resolve();
        var tokenHeader = 'X-Typo3-Cap-Token';

        function needsProof(href) {
            var url = sameOriginUrl(href);
            if (!url || ['captcha_proxy', 'typo3_cap_asset'].includes(url.searchParams.get('eID'))) {
                return Promise.resolve(false);
            }
            url.hash = '';
            if (!checks.has(url.href)) {
                // A probe never reaches the application or consumes a cookie.
                checks.set(url.href, nativeFetch(url.href, {
                    method: 'HEAD', headers: { 'X-Typo3-Cap-Probe': '1' }, cache: 'no-store'
                }).then(function (response) {
                    return response.headers.get('X-Typo3-Cap-Required') === '1';
                }).catch(function (error) {
                    checks.delete(url.href);
                    throw error;
                }));
            }
            return checks.get(url.href);
        }

        function requestProof(cancelled) {
            var result = queue.then(function () {
                if (cancelled()) return null;
                return waitForLibrary().then(function () {
                    if (cancelled()) return null;
                    var cap = new window.Cap({ apiEndpoint: config.apiEndpoint, 'data-cap-worker-count': '1' });
                    var timeout;
                    return Promise.race([
                        Promise.resolve().then(function () { return cap.solve(); }),
                        new Promise(function (_, reject) {
                            timeout = setTimeout(function () { reject(new Error('Challenge timed out')); }, 60000);
                        })
                    ]).then(function (solution) {
                        if (!solution || typeof solution.token !== 'string' || !solution.token) {
                            throw new Error('Cap returned no token');
                        }
                        return solution.token;
                    }).finally(function () {
                        clearTimeout(timeout);
                        cap.reset();
                        if (cap.widget && typeof cap.widget.cleanup === 'function') cap.widget.cleanup();
                    });
                });
            });
            queue = result.catch(function () {});
            return result;
        }

        function prepareRequest(href, method, cancelled) {
            var url = sameOriginUrl(href);
            if (!url || ['captcha_proxy', 'typo3_cap_asset'].includes(url.searchParams.get('eID'))) {
                return Promise.resolve(null);
            }
            var key = String(method).toUpperCase() + ' ' + url.pathname;
            var batch = batches.get(key);
            if (!batch || batch.members.length >= (config.requestLimit || 1)) {
                batch = { members: [], proof: null };
                batches.set(key, batch);
                // Capture a single event's requests before asynchronous probes can split them.
                batch.closed = delay(0).then(function () {
                    if (batches.get(key) === batch) batches.delete(key);
                });
            }
            batch.members.push(cancelled);
            return needsProof(href).then(function (required) {
                if (!required || cancelled()) return null;
                if (!batch.proof) {
                    batch.proof = batch.closed.then(function () {
                        return requestProof(function () {
                            return batch.members.every(function (isCancelled) { return isCancelled(); });
                        });
                    });
                }
                return batch.proof;
            });
        }

        window.fetch = function (input, init) {
            var request;
            try { request = new Request(input, init); } catch (error) { return Promise.reject(error); }
            if (!sameOriginUrl(request.url) || request.headers.has(tokenHeader)) return nativeFetch(request);
            return new Promise(function (resolve, reject) {
                function abort() { reject(request.signal.reason); }
                if (request.signal.aborted) return abort();
                request.signal.addEventListener('abort', abort, { once: true });
                prepareRequest(request.url, request.method, function () { return request.signal.aborted; }).then(function (token) {
                    request.signal.throwIfAborted();
                    if (!token) return nativeFetch(request);
                    var headers = new Headers(request.headers);
                    headers.set(tokenHeader, token);
                    // no-cors would silently discard the proof header.
                    return nativeFetch(request, {
                        headers: headers, mode: request.mode === 'no-cors' ? 'same-origin' : request.mode
                    });
                }).then(resolve, reject).finally(function () {
                    request.signal.removeEventListener('abort', abort);
                });
            });
        };

        var states = new WeakMap();
        var prototype = XMLHttpRequest.prototype;
        var open = prototype.open;
        var send = prototype.send;
        var abort = prototype.abort;
        prototype.open = function (method, url, async) {
            states.delete(this);
            if ((arguments.length < 3 || async) && sameOriginUrl(url)) {
                states.set(this, { url: new URL(url, document.baseURI).href, method: method, pending: false });
            }
            try { return open.apply(this, arguments); } catch (error) {
                states.delete(this);
                throw error;
            }
        };
        prototype.send = function () {
            var xhr = this;
            var state = states.get(xhr);
            if (!state || state.sent || xhr.readyState !== XMLHttpRequest.OPENED) return send.apply(xhr, arguments);
            if (state.pending) throw new DOMException('The request has already been sent.', 'InvalidStateError');
            state.pending = true;
            var args = arguments;
            function cancelled() { return states.get(xhr) !== state; }
            prepareRequest(state.url, state.method, cancelled).then(function (token) {
                if (cancelled()) return;
                if (token) xhr.setRequestHeader(tokenHeader, token);
                state.pending = false;
                state.sent = true;
                send.apply(xhr, args);
            }).catch(function (error) {
                if (cancelled()) return;
                states.delete(xhr);
                abort.call(xhr);
                console.warn('[Typo3Cap] Request challenge failed:', error);
                xhr.dispatchEvent(new ProgressEvent('error'));
                xhr.dispatchEvent(new ProgressEvent('loadend'));
            });
        };
        prototype.abort = function () {
            var state = states.get(this);
            states.delete(this);
            var pending = state && state.pending && this.readyState === XMLHttpRequest.OPENED;
            var result = abort.call(this);
            if (pending) {
                this.dispatchEvent(new ProgressEvent('abort'));
                this.dispatchEvent(new ProgressEvent('loadend'));
            }
            return result;
        };
    }

    function setupNavigation() {
        var browserNavigation = window.navigation && typeof window.navigation.addEventListener === 'function'
            ? window.navigation : null;
        if (browserNavigation) {
            browserNavigation.addEventListener('navigate', function (event) {
                var source = event.sourceElement;
                var form = source && (source.form || (source.tagName === 'FORM' ? source : null));
                var method = form && (source.hasAttribute('formmethod') ? source.formMethod : form.method);
                
                if (event.defaultPrevented || !event.cancelable || event.destination.sameDocument
                    || event.hashChange || event.downloadRequest != null || event.formData || method === 'post'
                    || event.navigationType === 'traverse' || !sameOriginUrl(event.destination.url)) return;
                if (resumingNavigation) {
                    // Once a request starts, its proof may already be consumed
                    prepared = null;
                    return;
                }
                event.preventDefault();
                navigate(function () {
                    resumingNavigation = true;
                    try {
                        if (event.navigationType === 'reload') window.location.reload();
                        else if (event.navigationType === 'replace') window.location.replace(event.destination.url);
                        else window.location.assign(event.destination.url);
                    } finally {
                        resumingNavigation = false;
                    }
                });
            });
        } else document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            var link = event.target.closest ? event.target.closest('a[href]') : null;
            if (!link || link.hasAttribute('download') || !targetsCurrentPage(link, 'target')) return;
            var url = sameOriginUrl(link.href);
            if (!url) return;
            if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
            if (readyToken()) return;
            event.preventDefault();
            navigate(function () { window.location.assign(url.href); });
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (resumingForms.has(form)) return;
            if (event.defaultPrevented) return;
            var submitter = event.submitter;
            var action = submitter && submitter.hasAttribute('formaction') ? submitter.formAction : form.action;
            var target = submitter && submitter.hasAttribute('formtarget') ? submitter : form;
            var method = submitter && submitter.hasAttribute('formmethod') ? submitter.formMethod : form.method;
            if (!sameOriginUrl(action) || !targetsCurrentPage(target, target === form ? 'target' : 'formtarget') || method === 'dialog') return;
            if (browserNavigation && method === 'get') return;
            if (readyToken()) return;
            event.preventDefault();
            navigate(function () {
                resumingForms.add(form);
                try {
                    HTMLFormElement.prototype.requestSubmit.call(form, submitter || undefined);
                } finally {
                    resumingForms.delete(form);
                }
            });
        });
    }

    function openChallengePage() {
        if (Number(readCookie(retryCookie)) >= 2) {
            showError(openChallengePage);
            return;
        }
        prepare().then(function () {
            // Only a successfully opened page clears this cookie counter.
            writeCookie(retryCookie, String((Number(readCookie(retryCookie)) || 0) + 1), 120);
            window.location.reload();
        }).catch(function (error) {
            console.warn('[Typo3Cap] Initial challenge failed:', error);
            showError(openChallengePage);
        });
    }

    window.Typo3Cap = { prepare: prepare };
    if (!config.challengePage) {
        setupRequests();
        setupNavigation();
    }
    function init() {
        if (config.challengePage) {
            openChallengePage();
            return;
        }
        writeCookie(retryCookie, '', 0);
        background();
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) clearTimeout(renewalTimer);
            else resumeBackground();
        });
        window.addEventListener('pagehide', function () {
            suspended = true;
            clearTimeout(renewalTimer);
        });
        window.addEventListener('pageshow', function (event) {
            suspended = false;
            if (event.persisted) {
                navigating = false;
                resumeBackground();
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
