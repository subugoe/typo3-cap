<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

use TYPO3\CMS\Install\Attribute\UpgradeWizard;

#[UpgradeWizard('typo3CapMigrateHcaptcha')]
final class MigrateHcaptchaWizard extends AbstractMigrationWizard
{
    public function getTitle(): string
    {
        return 'Migrate hCaptcha form elements to Cap';
    }

    public function getDescription(): string
    {
        return 'Replaces the hCaptcha form element and validator with the Cap equivalents in every .form.yaml definition and switches the hCaptcha static template include to Cap. Afterwards set tx_typo3cap.siteKey/secretKey/serviceUrl (or CAP_* environment variables) and run a Cap server; hCaptcha keys cannot be reused.';
    }

    protected function getFromType(): string
    {
        return 'Hcaptcha';
    }

    protected function getStaticTemplateFragment(): string
    {
        return 'hcaptcha/Configuration/TypoScript';
    }

    protected function getManualSteps(): array
    {
        return [
            'Include the "Cap Form Configuration" static template on pages with forms.',
            'Create a site in your Cap server and set its keys via Page TSconfig (tx_typo3cap.siteKey, tx_typo3cap.secretKey, tx_typo3cap.serviceUrl) or the CAP_SITE_KEY/CAP_SECRET_KEY/CAP_SERVICE_URL environment variables. hCaptcha keys cannot be reused.',
            'Remove the old extension: composer rem dreistromland/typo3-hcaptcha',
        ];
    }
}
