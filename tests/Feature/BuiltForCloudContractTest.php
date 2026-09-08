<?php

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);
uses(ContractAssertions::class);

test('the application satisfies the Built for Cloud contract', function (): void {
    $this->assertBuiltForCloudContract();
});

test('the application registers the Built for Cloud credential guard', function (): void {
    expect(auth()->guard('bfc'))->toBeInstanceOf(CredentialGuard::class);
});

test('an HTTP request succeeds with the committed session driver', function (): void {
    $filesystem = new Filesystem;
    $environmentPath = sys_get_temp_dir().'/slate-session-fallback-'.Str::uuid();
    $filesystem->ensureDirectoryExists($environmentPath);

    try {
        $result = Process::env(['SESSION_DRIVER' => false])->run([
            PHP_BINARY,
            base_path('tests/Fixtures/Session/boot-without-session-driver.php'),
            base_path(),
            $environmentPath,
        ]);
    } finally {
        $filesystem->deleteDirectory($environmentPath);
    }

    $result->throw();
    $observed = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

    expect($observed)->toBe([
        'session_driver_in_process' => false,
        'session_driver' => 'cookie',
        'up_status' => 200,
        'source_status' => 401,
    ]);
});

test('administrator status is guarded and cast to a boolean', function (): void {
    $user = new User;
    $user->forceFill(['is_admin' => 1]);

    expect($user->is_admin)->toBeTrue()
        ->and($user->getFillable())->not->toContain('is_admin');
});
