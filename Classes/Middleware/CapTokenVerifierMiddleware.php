<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Subugoe\Typo3Cap\Service\CapService;
use Subugoe\Typo3Cap\Service\NavigationProofService;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Stream;

final readonly class CapTokenVerifierMiddleware implements MiddlewareInterface
{
    public function __construct(
        private NavigationProofService $navigationProofs,
        private CapService $capService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $eID = $request->getQueryParams()['eID'] ?? '';
        if ('typo3_cap_asset' === $eID) {
            return $this->serveHandler($request);
        }

        $settings = $this->capService->getSettings($request);
        if ('captcha_proxy' === $eID) {
            return $this->handleProxyRequest($request, $settings);
        }
        $protected = $settings['enabled'] && $this->isPathProtected($request->getUri()->getPath(), $settings);
        if ('HEAD' === $request->getMethod() && '1' === $request->getHeaderLine('X-Typo3-Cap-Probe')) {
            // Decide in PHP so AJAX uses the same site settings and PCRE rules.
            return $this->noStore(new HtmlResponse('', 204, ['X-Typo3-Cap-Required' => $protected ? '1' : '0']));
        }
        if (!$settings['enabled'] || '' === $settings['protectedPaths']) {
            return $handler->handle($request);
        }

        $headerProof = $request->hasHeader('X-Typo3-Cap-Token');
        if ($protected && ('' === $settings['siteKey'] || '' === $settings['secretKey'])) {
            return $this->noStore(new HtmlResponse('Bot protection is temporarily unavailable. Please try again later.', 503));
        }

        try {
            $navigation = $this->navigationProofs->authorize(
                $request,
                $settings,
                $protected,
                fn (string $token): bool => $this->capService->verifyToken($token, $settings)
            );
        } catch (\Throwable) {
            error_log('[Typo3Cap] Navigation proof storage unavailable');
            if ($protected) {
                return $this->noStore(new HtmlResponse('Bot protection is temporarily unavailable. Please try again later.', 503));
            }
            $navigation = null;
        }
        if ($protected && null === $navigation) {
            // Never lose a POST body by redirecting it as GET.
            $response = !$headerProof && 'GET' === $request->getMethod() && $this->acceptsHtml($request)
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
        if (null !== $navigation) {
            $response = $this->noStore($response);
            if (!$headerProof && isset($request->getCookieParams()[$settings['cookieName']])) {
                $response = $this->clearTokenCookie($response, $request, $settings);
            }

            try {
                $expires = $this->navigationProofs->complete($navigation, $request, $response);
                if (!$headerProof && null !== $expires) {
                    $response = $response->withAddedHeader('Set-Cookie', $settings['cookieName'].'_navigation='.$navigation
                        .'; Max-Age='.max(0, $expires - time()).'; Path=/; HttpOnly; SameSite=Lax'.$this->secureCookieFlag($request));
                }
            } catch (\Throwable) {
                error_log('[Typo3Cap] Could not save navigation continuation');
            }
        }

        return $response;
    }

    private function isPathProtected(string $path, array $settings): bool
    {
        $patterns = $settings['protectedPaths'];
        if (str_contains($patterns, '#') && preg_match_all('/#[^#]+#[imsu]*/', $patterns, $matches)) {
            foreach ($matches[0] as $pattern) {
                if (1 === @preg_match($pattern, $path)) {
                    return true;
                }
            }

            return false;
        }
        foreach (array_filter(array_map(trim(...), explode(',', $patterns))) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function handleProxyRequest(ServerRequestInterface $request, array $settings): ResponseInterface
    {
        if (!$settings['enabled'] || '' === $settings['siteKey']) {
            return $this->noStore(new JsonResponse(['error' => 'cap_unavailable'], 503));
        }
        $path = $request->getQueryParams()['path'] ?? '';
        // Only the widget's /challenge and /redeem endpoints are exposed.
        $path = is_string($path) ? ltrim($path, '/') : '';
        if (!in_array($path, ['challenge', 'redeem'], true)) {
            return $this->noStore(new JsonResponse(['error' => 'invalid_path'], 404));
        }
        if ('POST' !== $request->getMethod()) {
            return $this->noStore(new JsonResponse(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST']));
        }
        $body = (string) $request->getBody();
        if (strlen($body) > 1048576) {
            return $this->noStore(new JsonResponse(['error' => 'payload_too_large'], 413));
        }
        $upstream = $this->capService->fetchUpstream(
            rtrim($settings['serviceUrl'], '/').'/'.rawurlencode($settings['siteKey']).'/'.$path,
            $body,
            $settings
        );
        if (null === $upstream) {
            return $this->noStore(new JsonResponse(['error' => 'proxy_failed'], 502));
        }

        return $this->noStore(new HtmlResponse($upstream['body'], $upstream['status'], [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function acceptsHtml(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return '' === $accept || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')->withHeader('Expires', '0')
            ->withoutHeader('ETag')->withoutHeader('Last-Modified')
        ;
    }

    private function clearTokenCookie(ResponseInterface $response, ServerRequestInterface $request, array $settings): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $settings['cookieName'].'=; Max-Age=0; Path=/; SameSite=Lax'.$this->secureCookieFlag($request));
    }

    private function secureCookieFlag(ServerRequestInterface $request): string
    {
        $normalized = $request->getAttribute('normalizedParams');
        $https = $normalized instanceof NormalizedParams ? $normalized->isHttps() : 'https' === $request->getUri()->getScheme();

        return $https ? '; Secure' : '';
    }

    private function scriptTags(ServerRequestInterface $request, array $settings, bool $challenge = false): string
    {
        $config = [
            'apiEndpoint' => $this->capService->getProxyEndpoint($request),
            'wasmUrl' => $settings['wasmUrl'],
            'cookieName' => $settings['cookieName'],
            'tokenTtlMs' => $settings['tokenTtl'] * 1000,
            'requestLimit' => NavigationProofService::MAX_AJAX_REQUESTS,
            'challengePage' => $challenge,
        ];
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $json = $escape(json_encode($config, JSON_THROW_ON_ERROR));
        $widget = $escape($settings['widgetUrl']);
        $handler = $escape($this->capService->getHandlerUrl($request));

        // Configure WASM before the widget's eager download starts.
        return '<script id="typo3-cap-handler" src="'.$handler.'" data-config="'.$json.'" defer></script>'
            .'<script src="'.$widget.'" defer></script>';
    }

    private function injectHandler(ResponseInterface $response, ServerRequestInterface $request, array $settings): ResponseInterface
    {
        if ('HEAD' === $request->getMethod() || 200 !== $response->getStatusCode()
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
            || $response->hasHeader('Content-Encoding') || '' === $settings['siteKey']) {
            return $response;
        }
        $html = (string) $response->getBody();
        if (str_contains($html, 'id="typo3-cap-handler"')) {
            return $response;
        }
        $position = strripos($html, '</body>');
        if (false === $position) {
            return $response;
        }
        $body = new Stream('php://temp', 'r+');
        $body->write(substr_replace($html, $this->scriptTags($request, $settings), $position, 0));
        $body->rewind();

        return $response->withBody($body)->withoutHeader('Content-Length')->withoutHeader('ETag');
    }

    private function createChallengeResponse(ServerRequestInterface $request, array $settings): ResponseInterface
    {
        $template = file_get_contents(__DIR__.'/../../Resources/Private/Templates/ChallengePage.html');
        if (false === $template) {
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

        return new HtmlResponse('HEAD' === $request->getMethod() ? '' : file_get_contents(__DIR__.'/../../Resources/Public/JavaScript/cap-handler.js'), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
