<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\ViewHelpers\Forms;

use Psr\Http\Message\ServerRequestInterface;
use Subugoe\Typo3Cap\Service\CapService;
use TYPO3\CMS\Core\Page\AssetCollector;
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
        // Must run before the widget script: its eager WASM download reads this global.
        $widget = '<cap-widget data-cap-api-endpoint="'.htmlspecialchars($this->capService->getProxyEndpoint($request), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" required></cap-widget>';
        if ('' !== $settings['wasmUrl']) {
            $wasm = htmlspecialchars(json_encode($settings['wasmUrl'], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<script>window.CAP_CUSTOM_WASM_URL='.$wasm.';</script>'.$widget;
        }

        return $widget;
    }
}
