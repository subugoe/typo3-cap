<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 CAP - CAPTCHA bot protection',
    'description' => 'Cap bot protection via PSR-15 middleware: path-based protection, invisible proof-of-work challenge, same-origin proxy, configurable via Page TSconfig or environment variables.',
    'category' => 'fe',
    'author' => 'SUB Göttingen',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
            'backend' => '12.4.0-13.4.99',
        ],
    ],
];
