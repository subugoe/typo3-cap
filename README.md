# TYPO3 CAP

TYPO3 middleware that requires a [Cap](https://capjs.js.org/guide/) proof-of-work token for each navigation to configured paths. An ordinary first visit briefly shows a loading page. Each rendered page then prepares the next token invisibly, so subsequent navigation can start immediately.

Composer package: `subugoe/typo3-cap`. TYPO3 extension key: `typo3_cap`.

## How it works

1. A protected request without a valid token receives a non-cacheable challenge page.
2. The shared browser handler solves Cap's challenge through the same-origin proxy, writes the token cookie, and reloads the requested URL.
3. The middleware validates and consumes that token through Cap's `/siteverify`, renders the page, and deletes the consumed token cookie. A short-lived navigation receipt allows a duplicate GET and redirects to finish using that verified proof.
4. The rendered page prepares a **new** token in the background. Links and forms use that cookie. If it is not ready yet, they wait on the current page without an overlay.

Cap verifies each token once. The server tracks its continuation for at most 10 seconds by default: one repeated GET to an already authorized URL and up to five same-origin redirects actually returned by the application. The expiry and allowances never reset. An unrelated URL or the next ordinary navigation requires a fresh proof.

The handler uses [Cap's programmatic mode](https://capjs.js.org/guide/programmatic.html). Each new proof gets a fresh widget instance because an existing instance can return its previous result. The proxy endpoint always ends in `path=/`, allowing Cap to append `challenge` and `redeem` correctly. No token is added to a URL or restored from browser storage.

## Configuration

Each setting uses this precedence: **root-page Page TSconfig → environment variable → default**. As a TYPO3 administrator, edit the site's root page under **Page properties → Resources → Page TSconfig** and add the settings you want to override:

```typoscript
tx_typo3cap {
    enabled = 1
    siteKey = your-site-key
    serviceUrl = http://cap:3000
    protectedPaths = /comma/,/separated/,/pages/
}
```

Values entered in this field are stored in TYPO3's database, so changes survive deployments and are shared by pods using that database. No new database table or backend form is needed. All pages and background proxy requests within a site use its root page's configuration; `protectedPaths` selects the sections to protect.

For example, leave `secretKey` undefined above and supply it through `CAP_SECRET_KEY`. Alternatively, configure everything through the PHP runtime environment:

```dotenv
CAP_ENABLED=true
CAP_SITE_KEY=your-site-key
CAP_SECRET_KEY=your-secret-key
CAP_SERVICE_URL=http://cap:3000
CAP_PROTECTED_PATHS=/comma/,/separated/,/pages/
```

The service URL is reachable from PHP; browsers use the extension's same-origin proxy. The proxy accepts only POST requests to `challenge` and `redeem`. The server secret stays in PHP.

`CAP_PROTECTED_PATHS` accepts comma-separated prefixes or `#`-delimited PCRE expressions, e.g. `#/members/.*#/#/conference/.*#`. An empty value disables protection. When enabled with protected paths, missing keys return 503 instead of silently allowing access.

All Page TSconfig keys below use the prefix `tx_typo3cap.`:

| Environment variable | Page TSconfig key | Default |
| --- | --- | --- |
| `CAP_ENABLED` | `enabled` | `false` |
| `CAP_SITE_KEY` | `siteKey` | empty |
| `CAP_SECRET_KEY` | `secretKey` | empty |
| `CAP_SERVICE_URL` | `serviceUrl` | `http://cap:3000` |
| `CAP_PROTECTED_PATHS` | `protectedPaths` | empty |
| `CAP_COOKIE_NAME` | `cookieName` | `typo3_cap_token` |
| `CAP_TIMEOUT` | `timeout` | 5 seconds per upstream request |
| `CAP_TOKEN_TTL` | `tokenTtl` | 300 seconds |
| `CAP_NAVIGATION_TTL` | `navigationTtl` | 10 seconds (1–30 allowed) |
| `CAP_WIDGET_URL` | `widgetUrl` | pinned `@cap.js/widget@0.1.57` on jsDelivr |
| `CAP_WASM_URL` | `wasmUrl` | `cap_wasm_bg.wasm` beside a custom widget; otherwise Cap's default |

Fallback applies per setting. Explicit values such as `enabled = 0` or an empty `protectedPaths` override the environment too. Environment lookup supports `$_ENV`, `$_SERVER`, and `getenv()`. No static template is required, and the extension does not register default Page TSconfig values that would mask environment values.

The middleware uses TYPO3's standard Page TSconfig API on both TYPO3 12.4 and 13.4, after site resolution and before eID handling or page rendering. Configure Cap at the site root using unconditional values. This keeps proof verification and background challenges consistent across pages, languages, and redirects.

`CAP_TOKEN_TTL` controls how long a prepared token is kept. Set it no higher than your Cap server's token lifetime. Visible pages renew shortly before this interval, and react to the widget's token-expiry event. Hidden pages pause scheduled renewal and resume when shown. Navigation preparation uses at most two workers.

`CAP_NAVIGATION_TTL` starts after successful verification and covers the entire redirect/duplicate sequence. The receipt is an opaque, HttpOnly cookie named `<CAP_COOKIE_NAME>_navigation`. Its server record binds authorization to the URL, query, method, and, for body-preserving redirects, request body. Duplicate POSTs remain rejected; this is not a substitute for application-level submission idempotency.

Continuation records use TYPO3's persistent `hash` cache, and TYPO3 locks serialize verification and allowance updates across PHP workers. Keep that cache persistent. Multi-node deployments need a shared cache and a locking strategy shared across nodes, or sticky routing that keeps a visitor's requests on one node. A cache flush invalidates continuations safely.

The extension always serves its bundled `cap-handler.js` through `?eID=typo3_cap_asset&v=9`. This works with Composer installations, subdirectories, and HTML `<base>` elements. To host the widget yourself, set `CAP_WIDGET_URL` to its public URL. With a custom widget URL, WASM automatically loads from `cap_wasm_bg.wasm` in the same directory. For example, `https://cap.example.com/assets/widget.js` uses `https://cap.example.com/assets/cap_wasm_bg.wasm`. Override this with `tx_typo3cap.wasmUrl` or `CAP_WASM_URL` if your file is elsewhere. The handler sets Cap's WASM URL before the widget loads, including on the initial challenge page. See [Cap's asset server documentation](https://capjs.js.org/guide/standalone/options#asset-server).

The asset server exposes four files. This extension uses Cap's invisible programmatic mode:

| Asset | Used by this extension | Purpose |
| --- | --- | --- |
| `/assets/widget.js` | Yes | Cap's widget and programmatic API. |
| `/assets/floating.js` | No | Optional floating widget UI. |
| `/assets/cap_wasm_bg.wasm` | Yes | Compiled proof-of-work solver. |
| `/assets/cap_wasm.js` | No | Standalone JavaScript bindings for direct WASM integration. |

The pinned widget includes its own WASM bindings and loads the binary directly, so it does not import `cap_wasm.js`. Both assets used by this extension load from the configured asset server when a custom widget URL is set.

A Content Security Policy must permit the widget, handler, solver resources, and Cap's blob workers; see [Cap's widget documentation](https://capjs.js.org/guide/widget.html). The extension does not loosen your site's policy.

Challenge completion reloads the requested page, preserving its query and fragment even when navigating before the next token is ready.

## EXT:form integration

Forms can protect themselves per form instead of by path. Include the **Cap Form Configuration (EXT:form)** static template on pages with forms, then add the **Cap (bot protection)** form element in the form editor. The element renders Cap's widget inside the form; the widget injects a hidden `cap-token` input on solve, and the bundled `Cap` validator verifies that token through Cap's `/siteverify` when the form is submitted.

Form-only mode requires `enabled = 1` with an empty `protectedPaths`: the same-origin proxy stays active while no navigation is protected. Pages protected by the middleware can host forms too; both protections then apply independently. The widget loads from `CAP_WIDGET_URL` on unprotected form pages; with a custom widget URL, the WASM URL is configured automatically. The element hides itself on summary pages and in email finishers. It uses Cap's native `required` attribute, so the browser blocks submission until the token is solved, and the server validator rejects missing or already-used tokens with an inline error (`error_cap_generic` in `locallang.xlf`).

## Navigation and failures

Ordinary same-origin links and forms are handled automatically, including dynamically inserted elements. Form validation, button values, and `formaction`/`formmethod` are preserved. Hash links, downloads, external links, and modified/new-tab clicks retain their native behavior.

In browsers with the [Navigation API](https://developer.mozilla.org/en-US/docs/Web/API/Navigation/navigate_event), script-driven same-origin document navigations also wait for a proof, including `location.href`, `assign()`, `replace()`, and `reload()`. Repeated calls during preparation share one navigation; canceled requests get a fresh proof. Older browsers use the link and form listeners.

Redirects with status 301, 302, 303, 307, or 308 are followed using the navigation receipt, including chains through unprotected pages. Relative destinations are resolved against the requested page. Cross-origin redirects require protection at the destination to verify independently. A 307/308 can reuse a proof only when its method and body match; non-seekable request bodies cannot be authorized for replay.

For programmatic navigation in older browsers, or code that submits a POST form directly with `form.submit()` (which skips submit events), prepare the cookie explicitly:

```js
await window.Typo3Cap.prepare();
window.location.assign('/protected/next-page');
```

Same-origin `fetch` and asynchronous `XMLHttpRequest` calls (including jQuery AJAX) made after the handler loads are handled automatically. A lightweight HEAD probe checks whether the target needs protection, using the server's path rules. Results are reused for the same URL on that page; public targets require no proof. Probes return no application content and consume no token.

Protected background requests started together in one event share a challenge when their path and method match. For example, a graph's count and distribution POSTs use one proof even when their query parameters differ. Groups contain at most four requests; later events or additional requests get a fresh proof. The server enforces the same path, method, request limit, and fixed `CAP_NAVIGATION_TTL` expiry. Repeated requests also consume a slot; actual redirects retain the existing continuation rules.

The shared token travels in `X-Typo3-Cap-Token`, without consuming or overwriting navigation cookies. Solving is queued with one worker, while the HTTP requests can run concurrently. POST bodies and response handling are preserved. Failed preparation rejects the fetch promise or fires an XHR error; POSTs are not automatically retried. Synchronous XHR and requests from workers are not intercepted. A rejected API request returns `403 {"error":"cap_required"}`.

A direct navigation before proof is ready, an expired continuation, or requests exceeding the duplicate/redirect allowances can still require the initial challenge page. Ordinary background failures stay unobtrusive. Navigation failures offer a retry button, and consecutive rejected solves stop after two automatic retries. Neither PHP sessions nor browser storage are required; cookies and JavaScript are required.

## Migrating from typo3-hcaptcha

1. Install this extension: `composer req subugoe/typo3-cap`. Keep `dreistromland/typo3-hcaptcha` installed until the migration has run.
2. Run **Admin Tools → Upgrade → Migrate hCaptcha form elements to Cap**. The wizard rewrites every `.form.yaml` (`type: Hcaptcha` element and `Hcaptcha` validator become `type: Cap` / `Cap`), switches the `hcaptcha` static template include to the Cap one, and lists the remaining manual steps.
3. Configure Cap as described under Configuration: `tx_typo3cap.siteKey`, `tx_typo3cap.secretKey`, and `tx_typo3cap.serviceUrl` (or the `CAP_*` environment variables). hCaptcha keys cannot be reused; create a site in your Cap server and use its keys.
4. Remove the old extension: `composer rem dreistromland/typo3-hcaptcha`. Its TypoScript constants (`plugin.tx_hcaptcha.settings.*`) become unused and can be deleted.

Semantics change from per-form hCaptcha checks to Cap's proof-of-work: the widget replaces the hCaptcha puzzle, failed verification shows the Cap widget error or the form validator's message instead of hCaptcha error codes, and a Cap server (trycap.dev) must be reachable.

## Migrating from typo3-altcha

The same upgrade flow: install this extension, run **Admin Tools → Upgrade → Migrate Altcha form elements to Cap** (rewrites `type: Altcha` elements and `Altcha` validators to `Cap`, switches the `altcha` static template include to the Cap one), then `composer rem bbysaeth/typo3-altcha`. The wizard lists the remaining manual steps: remove the `typo3-altcha` site set from your site's `config.yaml` if used, delete the unused `plugin.tx_altcha.settings.*` constants, and optionally drop the obsolete `tx_typo3altcha_domain_model_challenge` table.
