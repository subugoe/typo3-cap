<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Service;

use GuzzleHttp\Psr7\Uri as AssetUri;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

final class CapService
{
    /**
     * @throws \JsonException
     */
    public function getSettings(ServerRequestInterface $request): array
    {
        $defaults = [
            'enabled' => ['CAP_ENABLED', false],
            'siteKey' => ['CAP_SITE_KEY', ''],
            'secretKey' => ['CAP_SECRET_KEY', ''],
            'serviceUrl' => ['CAP_SERVICE_URL', 'http://cap:3000'],
            'protectedPaths' => ['CAP_PROTECTED_PATHS', ''],
            'cookieName' => ['CAP_COOKIE_NAME', 'typo3_cap_token'],
            'widgetUrl' => ['CAP_WIDGET_URL', 'https://cdn.jsdelivr.net/npm/@cap.js/widget@0.1.57/cap.min.js'],
            'wasmUrl' => ['CAP_WASM_URL', ''],
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
            $settings[$key] = $pageTsConfig[$key] ?? (false === $environment ? $default : $environment);
        }
        $settings['enabled'] = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN);
        $settings['timeout'] = max(1, (int) $settings['timeout']);
        $settings['tokenTtl'] = max(10, (int) $settings['tokenTtl']);
        $settings['navigationTtl'] = max(1, min(30, (int) $settings['navigationTtl']));
        foreach (array_diff(array_keys($defaults), ['enabled', 'timeout', 'tokenTtl', 'navigationTtl']) as $key) {
            $settings[$key] = trim((string) $settings[$key]);
        }
        if ('' === $settings['wasmUrl'] && $settings['widgetUrl'] !== $defaults['widgetUrl'][1]) {
            $widget = new AssetUri($settings['widgetUrl']);
            $settings['wasmUrl'] = (string) $widget
                ->withPath(preg_replace('~[^/]*$~', 'cap_wasm_bg.wasm', $widget->getPath(), 1))
                ->withQuery('')->withFragment('')
            ;
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $settings['cookieName'])) {
            $settings['cookieName'] = 'typo3_cap_token';
        }

        return $settings;
    }

    public function getHandlerUrl(ServerRequestInterface $request): string
    {
        return $this->getBaseUri($request).'?eID=typo3_cap_asset&v=9';
    }

    public function getProxyEndpoint(ServerRequestInterface $request): string
    {
        $base = $this->getBaseUri($request);
        $normalized = $request->getAttribute('normalizedParams');
        $publicUri = $normalized instanceof NormalizedParams
            ? new Uri($normalized->getRequestUrl())
            : $request->getUri();
        // Preserve site resolution for legacy URLs using ?id=123&L=0.
        parse_str($publicUri->getQuery(), $query);
        unset($query['eID'], $query['path']);
        $proxyQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $base.'?'.('' !== $proxyQuery ? $proxyQuery.'&' : '').'eID=captcha_proxy&path=/';
    }

    /**
     * @throws \JsonException
     */
    public function verifyToken(string $token, array $settings): bool
    {
        if (strlen($token) < 10 || strlen($token) > 4096 || !preg_match('/^[\x21-\x7e]+$/D', $token)) {
            return false;
        }
        $upstream = $this->fetchUpstream(
            rtrim($settings['serviceUrl'], '/').'/siteverify',
            json_encode(['secret' => $settings['secretKey'], 'response' => $token], JSON_THROW_ON_ERROR),
            $settings
        );
        if (null === $upstream || 200 !== $upstream['status']) {
            error_log('[Typo3Cap] Token verification service unavailable');

            return false;
        }
        $data = json_decode($upstream['body'], true);

        return is_array($data) && ($data['success'] ?? false) === true;
    }

    public function fetchUpstream(string $url, string $body, array $settings): ?array
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
        if (false === $result) {
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

    private function getBaseUri(ServerRequestInterface $request): string
    {
        $normalized = $request->getAttribute('normalizedParams');
        $publicUri = $normalized instanceof NormalizedParams
            ? new Uri($normalized->getRequestUrl())
            : $request->getUri();

        return (string) $publicUri->withQuery('')->withFragment('');
    }
}
