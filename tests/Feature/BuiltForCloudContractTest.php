<?php

declare(strict_types=1);

use App\Auth\SlateCredentialDeclaration;
use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\SelfServiceKindPolicyResolver;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\User;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);
uses(ContractAssertions::class);

test('the application satisfies the Built for Cloud host contract', function (): void {
    $this->assertBuiltForCloudContract();
    $this->assertBuiltForCloudHumanLifecycleContract();
    $this->assertBuiltForCloudThinHostConfiguration();
    $this->assertBuiltForCloudThinHostSources(base_path());

    expect(InstalledVersions::getPrettyVersion('artisan-build/built-for-cloud'))->toBe('v0.18.0')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(resolve(CredentialDeclaration::class))->toBeInstanceOf(SlateCredentialDeclaration::class)
        ->and(auth()->guard('bfc'))->toBeInstanceOf(CredentialGuard::class);
});

test('the application satisfies fleet conformance with only the package issue 123 schedule residue', function (): void {
    $packageRoot = base_path('vendor/artisan-build/built-for-cloud');
    $sort = static function (array $members): array {
        sort($members);

        return $members;
    };
    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sort([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sort([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sort([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            'ArtisanBuild\BuiltForCloud\Commands\FreshCommand',
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            DeliverOwnershipWebhook::class,
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sort([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => [],
    ];

    $report = (new FleetConformance($this))->inspect(new ConsumerConformance(
        consumer: 'slate',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: [app_path('Http/Middleware')],
        providerFiles: [$packageRoot.'/src/BuiltForCloudServiceProvider.php'],
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'tokens'],
        purposeMappings: [
            'slate.sources' => CredentialPurpose::Consumption,
        ],
        mcpServer: null,
        expected: $expected,
    ));

    $systemAuthorityViolations = $report->families['system_authority']->violations;
    $otherViolations = collect($report->families)
        ->except('system_authority')
        ->flatMap(static fn ($family): array => $family->violations)
        ->all();

    expect($report->passed)->toBeFalse()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES)
        ->and($otherViolations)->toBe([])
        ->and($systemAuthorityViolations)->toHaveCount(2)
        ->and($systemAuthorityViolations[0])->toStartWith('unexpected:')
        ->and($systemAuthorityViolations[1])->toStartWith('uninspectable-schedule:')
        ->and($systemAuthorityViolations[0])->toEndWith('artisan\' slate:prune-ingests')
        ->and($systemAuthorityViolations[1])->toEndWith('artisan\' slate:prune-ingests');
});

test('the package owns the configured landing human lifecycle and credential UI', function (): void {
    expect(Route::getRoutes()->getByName('bfc.landing'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('bfc.login'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('bfc.members.index'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('bfc.sessions.index'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('bfc.ui.installation-credentials.index'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('bfc.transitions.index'))->not->toBeNull();

    $this->get('/')->assertOk();
});

test('Slate declares only its sources purpose and no signing purpose', function (): void {
    $credentials = config('built-for-cloud.credentials');

    expect($credentials)->toBe([
        'guard' => 'bfc',
        'declaration' => SlateCredentialDeclaration::class,
        'session_guard' => 'web',
        'app_purposes' => [
            'slate.sources' => 'consumption',
        ],
    ])->and($credentials['app_purposes'])->not->toContain(CredentialPurpose::Signing->value)
        ->and(config('built-for-cloud.ui'))->toBe([
            'landing_page' => true,
            'member_management' => true,
            'personal_credentials' => false,
            'installation_credentials' => true,
            'session_management' => true,
            'managed_transitions' => true,
            'credential_purposes' => ['slate.sources'],
        ])->and(resolve(AppPurposeRegistry::class)->purpose('slate.sources'))->toBe(CredentialPurpose::Consumption)
        ->and(resolve(SelfServiceKindPolicyResolver::class)->installationKinds(
            new Subject(SubjectType::Installation, 'test-installation'),
        ))->toBe([CredentialKind::Bearer]);
});

test('Slate installation credential UI offers only Bearer for slate.sources', function (): void {
    $user = User::query()->create([
        'name' => 'Slate Owner',
        'email' => 'owner@example.test',
        'password' => bcrypt('password'),
    ]);
    $user->forceFill([
        'role' => 'owner',
        'status' => 'active',
        'email_verified_at' => now(),
        'original_contact_email' => 'owner@example.test',
    ])->save();
    $user->refresh();

    $response = $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ])->get('/bfc/ui/credentials/installation');

    $response->assertOk()
        ->assertSeeHtml('data-testid="installation-credentials-issue-option"')
        ->assertSee('slate.sources / bearer')
        ->assertDontSee('slate.sources / basic');
});

test('Slate installation credential JSON refuses Basic issuance', function (): void {
    $user = User::query()->create([
        'name' => 'Slate Owner',
        'email' => 'owner@example.test',
        'password' => bcrypt('password'),
    ]);
    $user->forceFill([
        'role' => 'owner',
        'status' => 'active',
        'email_verified_at' => now(),
        'original_contact_email' => 'owner@example.test',
    ])->save();
    $user->refresh();
    $credentialCount = Credential::query()->count();

    $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ])->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'test-installation',
        'kind' => CredentialKind::Basic->value,
        'purpose' => CredentialPurpose::Consumption->value,
    ])->assertForbidden();

    self::assertSame($credentialCount, Credential::query()->count());
});

test('personal credential issuance fails closed without a self-service subject', function (): void {
    $user = User::query()->create([
        'name' => 'Slate Owner',
        'email' => 'owner@example.test',
        'password' => bcrypt('password'),
    ]);
    $user->forceFill([
        'role' => 'owner',
        'status' => 'active',
        'email_verified_at' => now(),
        'original_contact_email' => 'owner@example.test',
    ])->save();
    $credentialCount = Credential::query()->count();

    $this->actingAs($user)->post('/bfc/ui/credentials/personal', [
        'name' => 'must-not-mint',
        'app_purpose' => 'slate.sources',
    ])->assertRedirect();

    self::assertSame($credentialCount, Credential::query()->count());
});

test('an HTTP request succeeds with the committed session driver', function (): void {
    $exampleEnvironment = parse_ini_file(base_path('.env.example'));

    self::assertIsArray($exampleEnvironment);
    self::assertSame('cookie', $exampleEnvironment['SESSION_DRIVER'] ?? null);
    self::assertSame('local-only-change-me', $exampleEnvironment['SLATE_INSTALLATION_SUBJECT_REF'] ?? null);
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
