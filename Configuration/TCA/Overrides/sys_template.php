<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

call_user_func(static function (): void {
    ExtensionManagementUtility::addStaticFile(
        'typo3_cap',
        'Configuration/TypoScript',
        'Cap Form Configuration (EXT:form)'
    );
});
