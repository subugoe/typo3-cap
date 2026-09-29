<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\ViewHelpers\Forms;

use Psr\Http\Message\ServerRequestInterface;
use Subugoe\Typo3Cap\Service\CapService;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use TYPO3\CMS\Form\ViewHelpers\RenderRenderableViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders the Cap widget for EXT:form. Inside a <form> the widget injects
 * a hidden "cap-token" input; the token is verified by CapValidator.
 */
class CapViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(private readonly CapService $capService, private readonly AssetCollector $assetCollector) {}

    /**
     * @throws \JsonException
     */
    public function render(): string
    {
        /** @var null|FormRuntime $formRuntime */
        $formRuntime = $this->renderingContext
            ->getViewHelperVariableContainer()
            ->get(RenderRenderableViewHelper::class, 'formRuntime')
        ;

        if ($formRuntime instanceof FormRuntime) {
            /**
             * @psalm-suppress InternalMethod
             */
            $renderingOptions = $formRuntime->getRenderingOptions();
            if (isset($renderingOptions['previewMode']) && true === $renderingOptions['previewMode']) {
                return '';
            }
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return '';
        }
        $settings = $this->capService->getSettings($request);

        $this->assetCollector->addJavaScript(
            'typo3_cap_widget',
            $settings['widgetUrl'],
            ['defer' => '']
        );

        // Must run before the widget script: it reads the globals below, and its
        // eager WASM download reads CAP_CUSTOM_WASM_URL.
        return $this->bootstrapScript($request, $settings)
            .'<cap-widget data-cap-api-endpoint="'.htmlspecialchars($this->capService->getProxyEndpoint($request), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" required></cap-widget>';
    }

    /**
     * Cap reads its configuration from globals before the widget upgrades, so
     * they have to be set by an inline script in front of it.
     *
     * A strict Content-Security-Policy needs that script to carry the request
     * nonce. The same nonce is handed to Cap as CAP_CSS_NONCE, because the
     * widget injects its stylesheet into a shadow root, where an inline <style>
     * is only allowed with a nonce - without it the widget renders unstyled and
     * its SVG circles fall back to the default black fill.
     *
     * @throws \JsonException
     */
    private function bootstrapScript(ServerRequestInterface $request, array $settings): string
    {
        $globals = [];
        $nonce = null;
        if ($settings['cspNonce']) {
            $consumable = $request->getAttribute('nonce');
            if ($consumable instanceof ConsumableNonce) {
                // Consume for both families, so TYPO3 keeps issuing the nonce
                // in cached pages even if only one of them allows it.
                $nonce = $consumable->consumeInline(Directive::StyleSrcElem);
                $consumable->consumeInline(Directive::ScriptSrcElem);
                $globals['CAP_CSS_NONCE'] = $nonce;
            }
        }
        if ('' !== $settings['wasmUrl']) {
            $globals['CAP_CUSTOM_WASM_URL'] = $settings['wasmUrl'];
        }
        if ([] === $globals) {
            return '';
        }
        $attribute = null === $nonce
            ? ''
            : ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        $source = '';
        foreach ($globals as $name => $value) {
            $source .= 'window.'.$name.'='.json_encode((string) $value, JSON_THROW_ON_ERROR).';';
        }

        return '<script'.$attribute.'>'.$source.'</script>';
    }
}
