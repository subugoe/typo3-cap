<?php

declare(strict_types=1);

use Subugoe\TypoCap\Middleware\CapTokenVerifierMiddleware;

return [
    'frontend' => [
        'subugoe/typo-cap/token-verifier' => [
            'target' => CapTokenVerifierMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/eid',
            ],
        ],
    ],
];
