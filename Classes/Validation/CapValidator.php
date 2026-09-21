<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Validation;

use Psr\Http\Message\ServerRequestInterface;
use Subugoe\Typo3Cap\Service\CapService;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

class CapValidator extends AbstractValidator
{
    protected $acceptsEmptyValues = false;

    public function __construct(private readonly CapService $capService) {}

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
            $this->addError($this->translateErrorMessage('error_cap_generic', 'Typo3Cap'), 1789000000);

            return;
        }
        $token = $request->getParsedBody()['cap-token'] ?? '';
        if (!is_string($token) || '' === $token) {
            $this->addError($this->translateErrorMessage('error_cap_generic', 'Typo3Cap'), 1789000001);

            return;
        }
        $settings = $this->capService->getSettings($request);
        if (!$this->capService->verifyToken($token, $settings)) {
            $this->addError($this->translateErrorMessage('error_cap_generic', 'Typo3Cap'), 1789000002);
        }
    }
}
