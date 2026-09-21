<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Validation;

use Psr\Http\Message\ServerRequestInterface;
use Subugoe\Typo3Cap\Service\CapService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

class CapValidator extends AbstractValidator
{
    protected $acceptsEmptyValues = false;

    private ?CapService $capService = null;

    /**
     * Validate the Cap token from the request and add an error if not valid.
     *
     * @param mixed $value The value
     *
     * @throws \JsonException
     */
    protected function isValid($value): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            $this->addError($this->translateErrorMessage(), 1789000000);

            return;
        }
        $token = $request->getParsedBody()['cap-token'] ?? '';
        if (!is_string($token) || '' === $token) {
            $this->addError($this->translateErrorMessage(), 1789000001);

            return;
        }
        $settings = $this->getCapService()->getSettings($request);
        if (!$this->getCapService()->verifyToken($token, $settings)) {
            $this->addError($this->translateErrorMessage(), 1789000002);
        }
    }

    private function translateErrorMessage(): string
    {
        return LocalizationUtility::translate('error_cap_generic', 'Typo3Cap')
            ?? 'Verifying the captcha failed. Please try again.';
    }

    private function getCapService(): CapService
    {
        return $this->capService ??= GeneralUtility::makeInstance(CapService::class);
    }
}
