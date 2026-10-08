<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Run;
use App\Models\Source;
use App\Slate\Storage\IngestStorage;
use ArtisanBuild\BuiltForCloud\Actions\OffboardSubject;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\OffboardOptions;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithSlateSourcesCredentials;
use Tests\TestCase;

final class AuthenticateSlateSourcesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithSlateSourcesCredentials;

    public function test_matching_installation_consumption_bearer_is_accepted_and_stamps_usage(): void
    {
        $credential = $this->mintCredential(['subject_ref' => 'installation-a']);

        $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'upload' => ['content_type' => 'video/mp4', 'size' => 0],
        ])->assertUnprocessable();

        self::assertNotNull($credential->credential->fresh()?->last_used_at);
    }

    public function test_account_bound_user_principal_consumption_bearer_is_accepted(): void
    {
        $user = $this->createPackageUser('credential-holder@example.test');
        $credential = $this->mintAccountCredential($user, 'user-'.$user->id);

        $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'upload' => ['content_type' => 'video/mp4', 'size' => 0],
        ])->assertUnprocessable();

        self::assertNotNull($credential->credential->fresh()?->last_used_at);
    }

    #[DataProvider('malformedAuthorizationProvider')]
    public function test_malformed_bearer_framing_returns_401_without_usage_or_side_effects(string $authorization): void
    {
        $credential = $this->mintCredential();

        $this->assertDeniedWithoutSideEffects($credential, $authorization);
    }

    public function test_unknown_bearer_returns_401_without_side_effects(): void
    {
        $this->assertDeniedWithoutSideEffects(null, 'Bearer unknown-test-credential');
    }

    public function test_missing_bearer_returns_401_without_side_effects(): void
    {
        $this->assertDeniedWithoutSideEffects();
    }

    public function test_wrong_purpose_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintNonSlateCredential();

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_signing_purpose_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential();
        DB::table('credentials')->where('id', $credential->credential->id)->update([
            'purpose' => CredentialPurpose::Signing->value,
        ]);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_installation_system_deployment_purpose_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential(['purpose' => CredentialPurpose::SystemDeployment]);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_pre_purpose_credential_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential();
        DB::table('credentials')->where('id', $credential->credential->id)->update([
            'purpose' => null,
        ]);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_wrong_credential_kind_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential(['kind' => CredentialKind::Basic]);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_wrong_installation_reference_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential(['subject_ref' => 'installation-b']);
        config()->set('slate.api.installation_subject_ref', 'installation-a');

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_a_fresh_install_authenticates_an_installation_credential_with_no_configured_binding(): void
    {
        config()->set('app.url', 'https://slate.example.test');
        $credential = $this->mintCredential(['subject_ref' => 'slate.example.test']);
        config()->set('slate.api.installation_subject_ref');

        $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'upload' => ['content_type' => 'video/mp4', 'size' => 0],
        ])->assertUnprocessable();

        self::assertNotNull($credential->credential->fresh()?->last_used_at);
    }

    public function test_another_installations_reference_returns_401_when_the_binding_is_unconfigured(): void
    {
        config()->set('app.url', 'https://slate.example.test');
        $credential = $this->mintCredential(['subject_ref' => 'other-installation.example']);
        config()->set('slate.api.installation_subject_ref');

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_a_credential_restored_from_another_installations_database_returns_401(): void
    {
        config()->set('app.url', 'https://slate.example.test');
        $credential = $this->mintCredential(['subject_ref' => 'slate.example.test']);
        config()->set('slate.api.installation_subject_ref');
        config()->set('app.url', 'https://slate-staging.example.test');

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_unresolvable_account_returns_401_before_usage_is_stamped(): void
    {
        $credential = $this->mintCredential([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'missing-user',
            'user_id' => '999999',
        ]);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_deactivated_account_returns_401_before_usage_is_stamped(): void
    {
        $user = $this->createPackageUser('deactivated@example.test');
        $credential = $this->mintAccountCredential($user, 'deactivated-account');
        $user->forceFill(['status' => 'inactive', 'deactivated_at' => now()])->save();
        StandaloneAccess::invalidateAccountBoundState($user);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_offboarded_account_returns_401_before_usage_is_stamped(): void
    {
        $user = $this->createPackageUser('offboarded@example.test');
        $credential = $this->mintAccountCredential($user, 'offboarded-account');
        resolve(OffboardSubject::class)(OffboardOptions::fromInput([
            'subject_type' => SubjectType::UserPrincipal->value,
            'subject_ref' => 'offboarded-account',
        ]));

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_mismatched_session_and_credential_accounts_return_401_before_usage_is_stamped(): void
    {
        $credentialUser = $this->createPackageUser('credential-holder@example.test');
        $sessionUser = $this->createPackageUser('session-holder@example.test');
        $credential = $this->mintAccountCredential($credentialUser, 'user-'.$credentialUser->id);
        $this->actingAs($sessionUser);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    public function test_browser_session_without_a_bearer_returns_401_without_side_effects(): void
    {
        $user = $this->createPackageUser('browser-session@example.test');
        $this->actingAs($user)->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
        ]);

        $this->assertDeniedWithoutSideEffects();
    }

    public function test_account_deactivation_ends_its_session_and_machine_access(): void
    {
        $user = $this->createPackageUser('removed-account@example.test');
        $credential = $this->mintAccountCredential($user, 'removed-account');
        $this->actingAs($user)->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
        ]);
        $user->forceFill(['status' => 'inactive', 'deactivated_at' => now()])->save();
        StandaloneAccess::invalidateAccountBoundState($user);

        $this->get('/bfc/members')
            ->assertForbidden()
            ->assertSessionMissing(StandaloneAccess::SESSION_VERSION_KEY);
        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
    }

    #[DataProvider('packageControlPlaneRouteProvider')]
    public function test_consumption_bearer_cannot_reach_package_control_plane_routes(
        string $method,
        string $path,
        int $status,
    ): void {
        $credential = $this->mintCredential();
        $credentialCount = DB::table('credentials')->count();
        $consoleKeyCount = DB::table('bfc_console_keys')->count();
        $offboardedSubjectCount = DB::table('offboarded_subjects')->count();
        Queue::fake();
        Storage::fake();
        Http::preventStrayRequests();

        $this->actingAsCredential($credential)->call($method, $path)->assertStatus($status);

        self::assertNull($credential->credential->fresh()?->last_used_at);
        self::assertSame($credentialCount, DB::table('credentials')->count());
        self::assertSame($consoleKeyCount, DB::table('bfc_console_keys')->count());
        self::assertSame($offboardedSubjectCount, DB::table('offboarded_subjects')->count());
        self::assertSame(0, Source::query()->count());
        self::assertSame(0, Run::query()->count());
        Queue::assertNothingPushed();
        Storage::disk()->assertDirectoryEmpty('/');
        Http::assertNothingSent();
    }

    public function test_revoked_and_expired_credentials_return_401_before_usage_is_stamped(): void
    {
        $revoked = $this->mintCredential(['subject_ref' => 'revoked-installation', 'revoked_at' => now()]);
        config()->set('slate.api.installation_subject_ref', 'revoked-installation');
        $this->assertDeniedWithoutSideEffects($revoked, $revoked->bearerHeader());

        $expired = $this->mintCredential(['subject_ref' => 'expired-installation', 'expires_at' => now()->subMinute()]);
        config()->set('slate.api.installation_subject_ref', 'expired-installation');
        $this->assertDeniedWithoutSideEffects($expired, $expired->bearerHeader());
    }

    /** @return array<string, array{string}> */
    public static function malformedAuthorizationProvider(): array
    {
        return [
            'lowercase scheme' => ['bearer placeholder'],
            'missing secret' => ['Bearer'],
            'double separator' => ['Bearer  placeholder'],
            'trailing material' => ['Bearer placeholder extra'],
            'basic framing' => ['Basic placeholder'],
        ];
    }

    /** @return array<string, array{string, string, int}> */
    public static function packageControlPlaneRouteProvider(): array
    {
        return [
            'members' => ['GET', '/bfc/members', 302],
            'sessions' => ['GET', '/bfc/me/sessions', 302],
            'credential management' => ['GET', '/bfc/installation/credentials', 302],
            'package control-plane state' => ['GET', '/bfc/client-observations', 401],
            'signing roots' => ['POST', '/bfc/console/re-key', 403],
            'system credential transport' => ['POST', '/bfc/credentials', 401],
        ];
    }

    private function assertDeniedWithoutSideEffects(
        ?MintedTestCredential $credential = null,
        ?string $authorization = null,
    ): void {
        Queue::fake();
        Storage::fake();
        Http::preventStrayRequests();
        $storage = $this->mock(IngestStorage::class);
        $storage->shouldNotReceive('temporaryUploadUrl');

        $request = $this;
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        $request->postJson('/api/v1/sources', [
            'upload' => ['content_type' => 'video/mp4', 'size' => 1024],
        ])->assertUnauthorized();

        if ($credential !== null) {
            self::assertNull($credential->credential->fresh()?->last_used_at);
        }

        self::assertSame(0, Source::query()->count());
        self::assertSame(0, Run::query()->count());
        Queue::assertNothingPushed();
        Storage::disk()->assertDirectoryEmpty('/');
        Http::assertNothingSent();
    }
}
