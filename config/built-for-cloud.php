<?php

declare(strict_types=1);

use App\Auth\SlateCredentialDeclaration;

return [
    'product' => 'Slate',
    'token_prefix' => 'slate_',
    'manifest' => [
        'name' => 'Slate',
        'slug' => 'slate',
        'description' => 'Self-hosted video rendering on Laravel Cloud.',
        'icon' => 'https://scalpels.app/img/products/transparent/slate.png',
        'product_url' => 'https://scalpels.app/products/slate',
    ],
    'credentials' => [
        'guard' => 'bfc',
        'declaration' => SlateCredentialDeclaration::class,
        'session_guard' => 'web',
        'app_purposes' => [
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
    'mcp' => [
        'path' => env('BUILT_FOR_CLOUD_MCP_PATH', '/mcp'),
        'write_path' => env('BUILT_FOR_CLOUD_MCP_WRITE_PATH', '/mcp-write'),
        'destructive_path' => null,
        'delegated' => true,
    ],
];
