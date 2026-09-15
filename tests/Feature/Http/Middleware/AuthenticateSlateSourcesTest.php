<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Run;
use App\Models\Source;
use App\Slate\Storage\IngestStorage;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $user = $this->createUser('credential-holder@example.test');
        $credential = $this->mintCredential([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'user-'.$user->id,
            'user_id' => (string) $user->id,
        ]);

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

    public function test_installation_credential_returns_401_when_the_binding_is_unconfigured(): void
    {
        $credential = $this->mintCredential();
        config()->set('slate.api.installation_subject_ref');

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

    public function test_mismatched_session_and_credential_accounts_return_401_before_usage_is_stamped(): void
    {
        $credentialUser = $this->createUser('credential-holder@example.test');
        $sessionUser = $this->createUser('session-holder@example.test');
        $credential = $this->mintCredential([
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'user-'.$credentialUser->id,
            'user_id' => (string) $credentialUser->id,
        ]);
        $this->actingAs($sessionUser);

        $this->assertDeniedWithoutSideEffects($credential, $credential->bearerHeader());
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

    private function createUser(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Package User',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
        $user->forceFill([
            'role' => 'member',
            'status' => 'active',
            'email_verified_at' => now(),
            'original_contact_email' => $email,
        ])->save();

        return $user;
    }
}
