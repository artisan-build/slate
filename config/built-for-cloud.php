<?php

declare(strict_types=1);

return [
    'product' => 'Slate',
    'token_prefix' => 'slate_',
    'manifest' => [
        'name' => 'Slate',
        'slug' => 'slate',
        'description' => 'Self-hosted video rendering on Laravel Cloud.',
        'icon' => 'https://scalpels.app/icons/slate.svg',
        'product_url' => 'https://scalpels.app/products/slate',
    ],
    'credentials' => [
        'guard' => 'bfc',
        'declaration' => null,
        'session_guard' => 'web',
        'app_purposes' => [
            'slate.delivery' => 'signing',
            'slate.sources' => 'consumption',
        ],
    ],
    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['slate.sources'],
    ],
];
