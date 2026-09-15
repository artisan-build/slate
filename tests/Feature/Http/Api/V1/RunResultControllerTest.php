<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunApiErrorReason;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WithSlateSourcesCredentials;
use Tests\TestCase;

final class RunResultControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithSlateSourcesCredentials;

    public function test_succeeded_run_returns_temporary_urls_without_reading_deliverables(): void
    {
        $this->travelTo('2026-09-05 13:00:00');
        config()->set('slate.storage.allow_host_local_fanout', true);
        Storage::fake();
        $run = Run::factory()->create(['status' => RunStatus::Succeeded]);
        $expectedKey = RunObjectKeys::deliveryOutput($run->id);
        $storage = $this->mock(ObjectStorage::class);
        $storage->shouldReceive('temporaryUrl')
            ->once()
            ->withArgs(static fn (ObjectKey $key, mixed $expiration): bool => $key->value() === $expectedKey->value()
                && $expiration->toISOString() === '2026-09-05T13:15:00.000000Z')
            ->andReturn('https://bucket.example.test/output.mp4?signature=signed');
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id.'/result');

        $response->assertOk()->assertExactJson([
            'id' => $run->id,
            'expires_at' => '2026-09-05T13:15:00.000000Z',
            'url' => 'https://bucket.example.test/output.mp4?signature=signed',
        ]);
    }

    public function test_succeeded_run_returns_intermediates_only_when_requested(): void
    {
        $this->travelTo('2026-09-05 13:00:00');
        config()->set('slate.storage.allow_host_local_fanout', true);
        Storage::fake();
        $run = Run::factory()->create(['status' => RunStatus::Succeeded]);
        $storage = $this->mock(ObjectStorage::class);
        $storage->shouldReceive('temporaryUrl')->twice()->andReturnUsing(
            static fn (ObjectKey $key): string => 'https://bucket.example.test/'.$key->value(),
        );
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)
            ->getJson('/api/v1/runs/'.$run->id.'/result?include_intermediates=1');

        $response->assertOk()->assertJsonPath('url', 'https://bucket.example.test/'.RunObjectKeys::deliveryOutput($run->id)->value())
            ->assertJsonPath('intermediates.0.operation_index', 0)
            ->assertJsonPath('intermediates.0.url', 'https://bucket.example.test/'.RunObjectKeys::operationOutput($run->id, 0)->value());
    }

    public function test_running_run_returns_409_result_not_ready(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id.'/result');

        $response->assertConflict()->assertExactJson([
            'reason' => RunApiErrorReason::ResultNotReady->value,
            'status' => 'running',
            'rejection_reason' => null,
        ]);
    }

    public function test_failed_run_returns_409_with_typed_rejection_reason(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Failed,
            'rejection_reason' => InputRejectionReason::DecodeCodecNotAllowed,
        ]);
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id.'/result');

        $response->assertConflict()->assertExactJson([
            'reason' => RunApiErrorReason::RunFailed->value,
            'status' => 'failed',
            'rejection_reason' => 'decode_codec_not_allowed',
        ]);
    }

    public function test_result_returns_404_without_signing_for_a_foreign_bfc_subject(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Succeeded,
            'owner_subject_ref' => 'tenant-a',
        ]);
        $storage = $this->mock(ObjectStorage::class);
        $storage->shouldNotReceive('temporaryUrl');
        $credential = $this->mintCredential([
            'subject_ref' => 'tenant-b',
        ]);

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id.'/result');

        $response->assertNotFound()->assertExactJson([
            'message' => 'The run was not found.',
            'reason' => RunApiErrorReason::RunNotFound->value,
        ]);
    }
}
