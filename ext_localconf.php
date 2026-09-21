<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

call_user_func(static function (): void {
    ExtensionManagementUtility::addTypoScript(
        'typo3_cap',
        'setup',
        'module.tx_form {
          settings {
            yamlConfigurations {
              1717171717 = EXT:typo3_cap/Configuration/Form/Yaml/BaseSetup.yaml
            }
          }
        }'
    );
});
