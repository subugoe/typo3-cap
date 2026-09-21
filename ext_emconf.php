<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 CAP - CAPTCHA bot protection',
    'description' => 'Cap bot protection via PSR-15 middleware (path-based protection, invisible proof-of-work challenge, same-origin proxy) and an EXT:form element with validator. Configurable via Page TSconfig or environment variables. Includes an upgrade wizard migrating from typo3-hcaptcha.',
    'category' => 'fe',
    'author' => 'SUB Göttingen',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-14.3.99',
            'backend' => '12.4.0-13.4.99',
            'extbase' => '12.4.0-13.4.99',
            'form' => '12.4.0-13.4.99',
        ],
    ],
];
