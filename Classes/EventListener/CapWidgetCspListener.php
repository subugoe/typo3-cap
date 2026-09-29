<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use Subugoe\Typo3Cap\Service\CapService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Allows the configured Cap widget in the site's Content-Security-Policy.
 *
 * The widget and its WASM proof-of-work solver are fetched by the browser, so
 * their origin needs to be part of script-src, script-src-elem and connect-src.
 * That origin is configuration and therefore differs per environment (a public
 * asset server in production, a local one in development), which is why it is
 * resolved here instead of being hardcoded in the site's policy.
 *
 * Set `cspSources = 0` to manage the sources in the site's policy instead.
 */
class CapWidgetCspListener
{
    public function __construct(private readonly CapService $capService) {}

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->isFrontendSite()) {
            return;
        }
        $request = $event->request ?? $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }
        $settings = $this->capService->getSettings($request);
        if (!$settings['cspSources']) {
            return;
        }

        $origins = [];
        foreach ([$settings['widgetUrl'], $settings['wasmUrl']] as $url) {
            $origin = $this->origin($url);
            if (null !== $origin) {
                $origins[$origin] = $origin;
            }
        }
        if ([] === $origins) {
            return;
        }

        $policy = $event->getCurrentPolicy();
        foreach (array_values($origins) as $origin) {
            $source = new UriValue($origin);
            $policy = $policy->extend(Directive::ScriptSrc, $source);
            $policy = $policy->extend(Directive::ScriptSrcElem, $source);
            $policy = $policy->extend(Directive::ConnectSrc, $source);
        }
        $event->setCurrentPolicy($policy);
    }

    /**
     * A relative URL is served by the site itself and needs no source.
     */
    private function origin(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
