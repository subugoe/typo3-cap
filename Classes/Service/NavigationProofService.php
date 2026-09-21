<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Service;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Locking\LockFactory;

final readonly class NavigationProofService
{
    public const MAX_AJAX_REQUESTS = 10;
    private const CACHE_PREFIX = 'typo3_cap_navigation_';
    private const MAX_REDIRECTS = 5;

    public function __construct(private LockFactory $lockFactory, private CacheManager $cacheManager, private readonly Context $context) {}

    /**
     * @throws \JsonException
     */
    public function authorize(ServerRequestInterface $request, array $settings, bool $protected, callable $verify): ?string
    {
        $cookies = $request->getCookieParams();
        // Background requests carry their own proof and never borrow navigation cookies.
        $headerProof = $request->hasHeader('X-Typo3-Cap-Token');
        $token = $headerProof ? $request->getHeaderLine('X-Typo3-Cap-Token') : ($cookies[$settings['cookieName']] ?? '');
        $receipt = $headerProof ? '' : ($cookies[$settings['cookieName'].'_navigation'] ?? '');
        $scope = hash('sha256', json_encode([
            $settings['serviceUrl'], $settings['siteKey'], $settings['secretKey'],
            $settings['cookieName'], $this->origin($this->publicUri($request)),
        ], JSON_THROW_ON_ERROR));
        if (is_string($token) && '' !== $token) {
            $identifier = self::CACHE_PREFIX.hash_hmac('sha256', $token, $scope);
        } elseif (is_string($receipt) && preg_match('/^typo3_cap_navigation_[a-f0-9]{64}$/D', $receipt)) {
            $identifier = $receipt;
            $token = '';
        } else {
            return null;
        }
        $fingerprint = $this->fingerprint($request, $this->publicUri($request), $request->getMethod());
        $ajaxScope = $headerProof ? $request->getMethod().' '.$this->publicUri($request)->getPath() : null;

        return $this->update($identifier, function (&$state) use ($identifier, $scope, $fingerprint, $ajaxScope, $request, $settings, $protected, $token, $verify): ?string {
            if (is_array($state)) {
                if ($state['scope'] !== $scope || null === $fingerprint || (isset($state['ajaxScope']) && null === $ajaxScope)) {
                    return null;
                }
                if (!isset($state['requests'][$fingerprint]) && in_array($fingerprint, $state['redirects'], true)) {
                    $state['requests'][$fingerprint] = true;
                } elseif (null !== $ajaxScope && ($state['ajaxScope'] ?? null) === $ajaxScope && $state['ajaxRemaining'] > 0) {
                    // A view can load several datasets; every request uses one slot, including repeats.
                    --$state['ajaxRemaining'];
                    $state['requests'][$fingerprint] = true;
                } elseif (!isset($state['ajaxScope']) && 'GET' === $request->getMethod() && isset($state['requests'][$fingerprint]) && !$state['duplicateUsed']) {
                    $state['duplicateUsed'] = true;
                } else {
                    return null;
                }
            } else {
                // Unprotected pages may continue redirects, but cannot consume a fresh proof.
                if (!$protected || '' === $token || !$verify($token)) {
                    return null;
                }
                $state = [
                    'scope' => $scope,
                    'expires' => time() + $settings['navigationTtl'],
                    'requests' => null === $fingerprint ? [] : [$fingerprint => true],
                    'redirects' => [],
                    'duplicateUsed' => false,
                    'ajaxScope' => $ajaxScope,
                    'ajaxRemaining' => self::MAX_AJAX_REQUESTS - 1,
                ];
            }

            return $identifier;
        });
    }

    public function complete(string $identifier, ServerRequestInterface $request, ResponseInterface $response): ?int
    {
        return $this->update($identifier, function (&$state) use ($request, $response): ?int {
            if (!is_array($state)) {
                return null;
            }
            $uri = $this->publicUri($request);
            $source = hash('sha256', $request->getMethod().' '.(string) $uri);
            $status = $response->getStatusCode();
            if (isset($state['redirects'][$source]) || count($state['redirects']) >= self::MAX_REDIRECTS
                || !in_array($status, [301, 302, 303, 307, 308], true) || !$response->hasHeader('Location')) {
                return $state['expires'];
            }

            try {
                $destination = UriResolver::resolve($uri, new Uri($response->getHeaderLine('Location')))->withFragment('');
                if ($this->origin($uri) === $this->origin($destination)) {
                    $method = $request->getMethod();
                    if ((303 === $status && 'HEAD' !== $method) || (in_array($status, [301, 302], true) && 'POST' === $method)) {
                        $method = 'GET';
                    }
                    $target = $this->fingerprint($request, $destination, $method);
                    if (null !== $target) {
                        $state['redirects'][$source] = $target;
                    }
                }
            } catch (\InvalidArgumentException) {
                // An invalid Location cannot authorize another request.
            }

            return $state['expires'];
        });
    }

    private function publicUri(ServerRequestInterface $request): UriInterface
    {
        $normalized = $request->getAttribute('normalizedParams');

        return ($normalized instanceof NormalizedParams ? new Uri($normalized->getRequestUrl()) : $request->getUri())->withFragment('');
    }

    private function origin(UriInterface $uri): string
    {
        return strtolower($uri->getScheme().'://'.$uri->getHost()).':'.($uri->getPort() ?? ('https' === $uri->getScheme() ? 443 : 80));
    }

    private function fingerprint(ServerRequestInterface $request, UriInterface $uri, string $method): ?string
    {
        $content = '';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $body = $request->getBody();
            if (!$body->isSeekable()) {
                return null;
            }
            $position = $body->tell();

            try {
                $content = $request->getHeaderLine('Content-Type').':'.Utils::hash($body, 'sha256');
            } finally {
                $body->seek($position);
            }
        }

        return hash('sha256', $method.' '.(string) $uri->withFragment('')."\n".$content);
    }

    private function update(string $identifier, callable $operation): mixed
    {
        $lock = $this->lockFactory->createLocker('typo3-cap-navigation-'.substr($identifier, -2));
        if (!$lock->acquire()) {
            throw new \RuntimeException('Could not lock navigation proof', 8611900091);
        }

        try {
            $cache = $this->cacheManager->getCache('hash');
            $state = $cache->get($identifier);
            if (is_array($state) && $state['expires'] <= time()) {
                return null;
            }
            $previous = $state;
            $result = $operation($state);
            if ($state !== $previous) {
                // Typo3 measures TTLs from request start; never extend the original expiry.
                $start = min(time(), (int) ($this->context->getPropertyFromAspect('date', 'timestamp') ?? time()));
                $cache->set($identifier, $state, ['typo3_cap_navigation'], max(1, $state['expires'] - $start));
            }

            return $result;
        } finally {
            $lock->release();
        }
    }
}
