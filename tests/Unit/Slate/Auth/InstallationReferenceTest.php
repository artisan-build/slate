<?php

declare(strict_types=1);

use App\Slate\Auth\InstallationReference;

test('derives the installation reference from the application URL host', function (mixed $url, string $expected): void {
    expect(InstallationReference::fromApplicationUrl($url))->toBe($expected);
})->with([
    'production host' => ['https://slate.acme.com', 'slate.acme.com'],
    'host with port' => ['http://localhost:8000', 'localhost'],
    'mixed case and trailing slash' => ['https://Slate.Acme.COM/', 'slate.acme.com'],
    'no scheme' => ['slate.acme.com', 'slate.acme.com'],
    'no scheme with port and path' => ['slate.acme.com:8000/path', 'slate.acme.com'],
    'user info is discarded' => ['https://user:secret@slate.acme.com', 'slate.acme.com'],
    'ip address' => ['http://127.0.0.1', '127.0.0.1'],
    'surrounding whitespace' => ['  https://slate.acme.com  ', 'slate.acme.com'],
    'empty string' => ['', 'localhost'],
    'whitespace only' => ['   ', 'localhost'],
    'null' => [null, 'localhost'],
    'non-string' => [false, 'localhost'],
]);

test('distinct application URLs derive distinct installation references', function (): void {
    expect(InstallationReference::fromApplicationUrl('https://slate.acme.com'))
        ->not->toBe(InstallationReference::fromApplicationUrl('https://slate-staging.acme.com'));
});
