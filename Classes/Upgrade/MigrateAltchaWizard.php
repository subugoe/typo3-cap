<?php

declare(strict_types=1);

namespace Subugoe\Typo3Cap\Upgrade;

use TYPO3\CMS\Install\Attribute\UpgradeWizard;

#[UpgradeWizard('typo3CapMigrateAltcha')]
final class MigrateAltchaWizard extends AbstractMigrationWizard
{
    public function getTitle(): string
    {
        return 'Migrate Altcha form elements to Cap';
    }

    public function getDescription(): string
    {
        return 'Replaces the Altcha form element and validator with the Cap equivalents in every .form.yaml definition and switches the Altcha static template include to Cap. Afterwards set tx_typo3cap.siteKey/secretKey/serviceUrl (or CAP_* environment variables) and run a Cap server.';
    }

    protected function getFromType(): string
    {
        return 'Altcha';
    }

    protected function getStaticTemplateFragment(): string
    {
        return 'altcha/Configuration/TypoScript';
    }

    protected function getManualSteps(): array
    {
        return [
            'Include the "Cap Form Configuration" static template on pages with forms.',
            'If your site includes the "typo3-altcha" site set, remove it from the site\'s config.yaml.',
            'Create a site in your Cap server and set its keys via Page TSconfig (tx_typo3cap.siteKey, tx_typo3cap.secretKey, tx_typo3cap.serviceUrl) or the CAP_SITE_KEY/CAP_SECRET_KEY/CAP_SERVICE_URL environment variables.',
            'Altcha\'s TypoScript constants (plugin.tx_altcha.settings.*, altcha.*) become unused and can be deleted.',
            'The tx_typo3altcha_domain_model_challenge table becomes obsolete and can be dropped.',
            'Review your Content-Security-Policy: Cap\'s widget and proof-of-work solver need their own allowances; see the extension README.',
            'Remove the old extension: composer rem bbysaeth/typo3-altcha',
        ];
    }
}
