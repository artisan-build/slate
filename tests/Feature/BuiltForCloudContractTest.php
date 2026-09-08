<?php

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);
uses(ContractAssertions::class);

test('the application satisfies the Built for Cloud contract', function (): void {
    $this->assertBuiltForCloudContract();
});

test('the application registers the Built for Cloud credential guard', function (): void {
    expect(auth()->guard('bfc'))->toBeInstanceOf(CredentialGuard::class);
});

test('an HTTP request succeeds with the committed session driver', function (): void {
    $exampleEnvironment = parse_ini_file(base_path('.env.example'));

    self::assertIsArray($exampleEnvironment);
    $committedDriver = $exampleEnvironment['SESSION_DRIVER'] ?? null;
    config()->set('session.driver', $committedDriver);

    expect($committedDriver)->toBe('cookie')
        ->and(file_get_contents(config_path('session.php')))->toContain("env('SESSION_DRIVER', 'cookie')");
    $this->get('/up')->assertOk();
});

test('administrator status is guarded and cast to a boolean', function (): void {
    $user = new User;
    $user->forceFill(['is_admin' => 1]);

    expect($user->is_admin)->toBeTrue()
        ->and($user->getFillable())->not->toContain('is_admin');
});
