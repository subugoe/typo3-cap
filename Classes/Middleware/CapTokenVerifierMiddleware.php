<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Subugoe\Typo3Cap\Service\NavigationProofService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

final class CapTokenVerifierMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly NavigationProofService $navigationProofs = new NavigationProofService(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $eID = $request->getQueryParams()['eID'] ?? '';
        if ($eID === 'typo3_cap_asset') {
            return $this->serveHandler($request);
        }

        $settings = $this->getCapSettings($request);
        if ($eID === 'captcha_proxy') {
            return $this->handleProxyRequest($request, $settings);
        }
        $protected = $settings['enabled'] && $this->isPathProtected($request->getUri()->getPath(), $settings);
        if ($request->getMethod() === 'HEAD' && $request->getHeaderLine('X-Typo3-Cap-Probe') === '1') {
            // Decide in PHP so AJAX uses the same site settings and PCRE rules.
            return $this->noStore(new HtmlResponse('', 204, ['X-Typo3-Cap-Required' => $protected ? '1' : '0']));
        }
        if (!$settings['enabled'] || $settings['protectedPaths'] === '') {
            return $handler->handle($request);
        }

        $headerProof = $request->hasHeader('X-Typo3-Cap-Token');
        if ($protected && ($settings['siteKey'] === '' || $settings['secretKey'] === '')) {
            return $this->noStore(new HtmlResponse('Bot protection is temporarily unavailable. Please try again later.', 503));
        }
        try {
            $navigation = $this->navigationProofs->authorize(
                $request, $settings, $protected, fn (string $token): bool => $this->verifyToken($token, $settings)
            );
        } catch (\Throwable) {
            error_log('[Typo3Cap] Navigation proof storage unavailable');
            if ($protected) {
                return $this->noStore(new HtmlResponse('Bot protection is temporarily unavailable. Please try again later.', 503));
            }
            $navigation = null;
        }
        if ($protected && $navigation === null) {
            // Never lose a POST body by redirecting it as GET.
            $response = !$headerProof && $request->getMethod() === 'GET' && $this->acceptsHtml($request)
                ? $this->createChallengeResponse($request, $settings)
                : new JsonResponse(['error' => 'cap_required'], 403);
            $response = $this->noStore($response);
            return $headerProof ? $response : $this->clearTokenCookie($response, $request, $settings);
        }

        $response = $handler->handle($request);
        if ($protected) {
            // Inject into protected HTML, including TYPO3 page-cache hits.
            $response = $this->injectHandler($response, $request, $settings);
        }
        if ($navigation !== null) {
            $response = $this->noStore($response);
            if (!$headerProof && isset($request->getCookieParams()[$settings['cookieName']])) {
                $response = $this->clearTokenCookie($response, $request, $settings);
            }
            try {
                $expires = $this->navigationProofs->complete($navigation, $request, $response);
                if (!$headerProof && $expires !== null) {
                    $response = $response->withAddedHeader('Set-Cookie', $settings['cookieName'] . '_navigation=' . $navigation
                        . '; Max-Age=' . max(0, $expires - time()) . '; Path=/; HttpOnly; SameSite=Lax' . $this->secureCookieFlag($request));
                }
            } catch (\Throwable) {
                error_log('[Typo3Cap] Could not save navigation continuation');
            }
        }
        return $response;
    }

    private function getCapSettings(ServerRequestInterface $request): array
    {
        $defaults = [
            'enabled' => ['CAP_ENABLED', false],
            'siteKey' => ['CAP_SITE_KEY', ''],
            'secretKey' => ['CAP_SECRET_KEY', ''],
            'serviceUrl' => ['CAP_SERVICE_URL', 'http://cap:3000'],
            'protectedPaths' => ['CAP_PROTECTED_PATHS', ''],
            'cookieName' => ['CAP_COOKIE_NAME', 'typo3_cap_token'],
            'handlerPath' => ['CAP_HANDLER_PATH', ''],
            'widgetUrl' => ['CAP_WIDGET_URL', 'https://cdn.jsdelivr.net/npm/@cap.js/widget@0.1.57/cap.min.js'],
            'timeout' => ['CAP_TIMEOUT', 5],
            'tokenTtl' => ['CAP_TOKEN_TTL', 300],
            'navigationTtl' => ['CAP_NAVIGATION_TTL', 10],
        ];
        $site = $request->getAttribute('site');
        $pageTsConfig = $site instanceof Site
            ? (BackendUtility::getPagesTSconfig($site->getRootPageId())['tx_typo3cap.'] ?? [])
            : [];
        $settings = [];
        foreach ($defaults as $key => [$variable, $default]) {
            $environment = $_ENV[$variable] ?? $_SERVER[$variable] ?? getenv($variable);
            $settings[$key] = $pageTsConfig[$key] ?? ($environment === false ? $default : $environment);
        }
        $settings['enabled'] = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN);
        $settings['timeout'] = max(1, (int) $settings['timeout']);
        $settings['tokenTtl'] = max(10, (int) $settings['tokenTtl']);
        $settings['navigationTtl'] = max(1, min(30, (int) $settings['navigationTtl']));
        foreach (array_diff(array_keys($defaults), ['enabled', 'timeout', 'tokenTtl', 'navigationTtl']) as $key) {
            $settings[$key] = trim((string) $settings[$key]);
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $settings['cookieName'])) {
            $settings['cookieName'] = 'typo3_cap_token';
        }
        return $settings;
    }

    private function isPathProtected(string $path, array $settings): bool
    {
        $patterns = $settings['protectedPaths'];
        if (str_contains($patterns, '#') && preg_match_all('/#[^#]+#[imsu]*/', $patterns, $matches)) {
            foreach ($matches[0] as $pattern) {
                if (@preg_match($pattern, $path) === 1) {
                    return true;
                }
            }
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', $patterns))) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function verifyToken(string $token, array $settings): bool
    {
        if (strlen($token) < 10 || strlen($token) > 4096 || !preg_match('/^[\x21-\x7e]+$/D', $token)) {
            return false;
        }
        $upstream = $this->fetchUpstream(
            rtrim($settings['serviceUrl'], '/') . '/siteverify',
            json_encode(['secret' => $settings['secretKey'], 'response' => $token], JSON_THROW_ON_ERROR),
            $settings
        );
        if ($upstream === null || $upstream['status'] !== 200) {
            error_log('[Typo3Cap] Token verification service unavailable');
            return false;
        }
        $data = json_decode($upstream['body'], true);
        return is_array($data) && ($data['success'] ?? false) === true;
    }

    private function handleProxyRequest(ServerRequestInterface $request, array $settings): ResponseInterface
    {
        if (!$settings['enabled'] || $settings['siteKey'] === '') {
            return $this->noStore(new JsonResponse(['error' => 'cap_unavailable'], 503));
        }
        $path = $request->getQueryParams()['path'] ?? '';
        // Only the widget's /challenge and /redeem endpoints are exposed.
        $path = is_string($path) ? ltrim($path, '/') : '';
        if (!in_array($path, ['challenge', 'redeem'], true)) {
            return $this->noStore(new JsonResponse(['error' => 'invalid_path'], 404));
        }
        if ($request->getMethod() !== 'POST') {
            return $this->noStore(new JsonResponse(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST']));
        }
        $body = (string) $request->getBody();
        if (strlen($body) > 1048576) {
            return $this->noStore(new JsonResponse(['error' => 'payload_too_large'], 413));
        }
        $upstream = $this->fetchUpstream(
            rtrim($settings['serviceUrl'], '/') . '/' . rawurlencode($settings['siteKey']) . '/' . $path,
            $body,
            $settings
        );
        if ($upstream === null) {
            return $this->noStore(new JsonResponse(['error' => 'proxy_failed'], 502));
        }
        return $this->noStore(new HtmlResponse($upstream['body'], $upstream['status'], [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function fetchUpstream(string $url, string $body, array $settings): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => ['Content-Type: application/json', 'Accept: application/json'],
            'content' => $body,
            'timeout' => $settings['timeout'],
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return null;
        }
        $status = 502;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        return ['body' => $result, 'status' => $status];
    }

    private function acceptsHtml(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        return $accept === '' || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')->withHeader('Expires', '0')
            ->withoutHeader('ETag')->withoutHeader('Last-Modified');
    }

    private function clearTokenCookie(ResponseInterface $response, ServerRequestInterface $request, array $settings): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $settings['cookieName'] . '=; Max-Age=0; Path=/; SameSite=Lax' . $this->secureCookieFlag($request));
    }

    private function secureCookieFlag(ServerRequestInterface $request): string
    {
        $normalized = $request->getAttribute('normalizedParams');
        $https = $normalized instanceof NormalizedParams ? $normalized->isHttps() : $request->getUri()->getScheme() === 'https';
        return $https ? '; Secure' : '';
    }

    private function scriptTags(ServerRequestInterface $request, array $settings, bool $challenge = false): string
    {
        // Use the public page URL for assets/proxy calls, including any proxy prefix.
        $normalized = $request->getAttribute('normalizedParams');
        $publicUri = $normalized instanceof NormalizedParams
            ? new Uri($normalized->getRequestUrl())
            : $request->getUri();
        $base = (string) $publicUri->withQuery('')->withFragment('');
        // Preserve site resolution for legacy URLs using ?id=123&L=0.
        parse_str($publicUri->getQuery(), $query);
        unset($query['eID'], $query['path']);
        $proxyQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $config = [
            'apiEndpoint' => $base . '?' . ($proxyQuery !== '' ? $proxyQuery . '&' : '') . 'eID=captcha_proxy&path=/',
            'cookieName' => $settings['cookieName'],
            'tokenTtlMs' => $settings['tokenTtl'] * 1000,
            'requestLimit' => NavigationProofService::MAX_AJAX_REQUESTS,
            'challengePage' => $challenge,
        ];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = $escape(json_encode($config, JSON_THROW_ON_ERROR));
        $widget = $escape($settings['widgetUrl']);
        $handler = $escape($settings['handlerPath'] ?: $base . '?eID=typo3_cap_asset&v=8');
        return '<script src="' . $widget . '" defer></script>'
            . '<script id="typo3-cap-handler" src="' . $handler . '" data-config="' . $json . '" defer></script>';
    }

    private function injectHandler(ResponseInterface $response, ServerRequestInterface $request, array $settings): ResponseInterface
    {
        if ($request->getMethod() === 'HEAD' || $response->getStatusCode() !== 200
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
            || $response->hasHeader('Content-Encoding') || $settings['siteKey'] === '') {
            return $response;
        }
        $html = (string) $response->getBody();
        if (str_contains($html, 'id="typo3-cap-handler"')) {
            return $response;
        }
        $position = strripos($html, '</body>');
        if ($position === false) {
            return $response;
        }
        $body = new Stream('php://temp', 'r+');
        $body->write(substr_replace($html, $this->scriptTags($request, $settings), $position, 0));
        $body->rewind();
        return $response->withBody($body)->withoutHeader('Content-Length')->withoutHeader('ETag');
    }

    private function createChallengeResponse(ServerRequestInterface $request, array $settings): ResponseInterface
    {
        $template = file_get_contents(__DIR__ . '/../../Resources/Private/Templates/ChallengePage.html');
        if ($template === false) {
            return new HtmlResponse('One moment please…', 403);
        }
        $html = str_replace('<!-- TYPO3_CAP_SCRIPTS -->', $this->scriptTags($request, $settings, true), $template);
        return new HtmlResponse($html, 403);
    }

    private function serveHandler(ServerRequestInterface $request): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new HtmlResponse('', 405, ['Allow' => 'GET, HEAD']);
        }
        return new HtmlResponse($request->getMethod() === 'HEAD' ? '' : file_get_contents(__DIR__ . '/../../Resources/Public/JavaScript/cap-handler.js'), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
