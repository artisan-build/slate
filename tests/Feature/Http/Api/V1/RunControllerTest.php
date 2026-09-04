<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Jobs\CompleteRun;
use App\Jobs\RenderRunOperation;
use App\Models\Run;
use App\Models\RunStatus;
use App\Models\RunTrust;
use App\Models\User;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunApiErrorReason;
use App\Slate\Run\RunRecipe;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Webhook\HostnameResolver;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DatabaseQueueRunDriver implements Driver
{
    public function run(CompiledRecipe $recipe): DriverResult
    {
        $command = $recipe->command();
        $output = $command[count($command) - 1];

        if (in_array('-show_format', $command, true)) {
            return new DriverResult(json_encode([
                'streams' => [
                    [
                        'codec_type' => 'video',
                        'codec_name' => 'h264',
                        'width' => 1536,
                        'height' => 1024,
                        'avg_frame_rate' => '24/1',
                        'pix_fmt' => 'yuv420p',
                        'color_range' => 'tv',
                        'nb_frames' => '24',
                    ],
                    ['codec_type' => 'audio', 'codec_name' => 'aac'],
                ],
                'format' => ['duration' => '1.000000'],
            ], JSON_THROW_ON_ERROR));
        }

        file_put_contents($output, 'database queue rendered output');

        return new DriverResult;
    }
}

final readonly class ApiHostnameResolver implements HostnameResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses) {}

    public function resolve(string $hostname): array
    {
        return $this->addresses;
    }
}

final class RunControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithCredentials;

    public function test_valid_payload_creates_run_calls_executor_and_returns_202(): void
    {
        Bus::fake();
        $runId = '41414141-4141-4141-8141-414141414141';
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $this->validPayload($runId));

        $response->assertAccepted()->assertExactJson(['id' => $runId]);
        $run = Run::query()->findOrFail($runId);
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunTrust::Untrusted, $run->trust);
        self::assertSame(SubjectType::Application, $run->owner_subject_type);
        self::assertSame('test-subject', $run->owner_subject_ref);
        self::assertSame($this->validPayload($runId)['recipe'], $run->recipe()->toArray());
        Bus::assertChained([RenderRunOperation::class, CompleteRun::class]);
    }

    public function test_submit_owns_run_by_the_bfc_credential_subject_when_the_guard_principal_is_a_user(): void
    {
        Bus::fake();
        $runId = '57575757-5757-4757-8757-575757575757';
        $user = User::factory()->create();
        $credential = $this->mintCredential([
            'abilities' => ['consume'],
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'installation-57',
            'user_id' => (string) $user->id,
        ]);

        $this->actingAsCredential($credential)->postJson('/api/v1/runs', $this->validPayload($runId))
            ->assertAccepted();

        $run = Run::query()->findOrFail($runId);
        self::assertSame(SubjectType::Installation, $run->owner_subject_type);
        self::assertSame('installation-57', $run->owner_subject_ref);
        self::assertNotSame((string) $user->id, $run->owner_subject_ref);
    }

    public function test_malformed_typed_recipe_returns_422_reason_without_creating_run(): void
    {
        Bus::fake();
        $runId = '42424242-4242-4242-8242-424242424242';
        $payload = $this->validPayload($runId);
        $payload['recipe']['operations'][0]['encoder'] = 'caller-controlled';
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', RunApiErrorReason::MalformedRecipe->value)
            ->assertJsonValidationErrors(['recipe']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_unexpected_top_level_field_returns_422_typed_reason(): void
    {
        Bus::fake();
        $runId = '43434343-4343-4343-8343-434343434343';
        $payload = [...$this->validPayload($runId), 'command' => ['-i', 'secret']];
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', RunApiErrorReason::MalformedRecipe->value)
            ->assertJsonValidationErrors(['payload']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
    }

    public function test_submit_returns_single_reveal_webhook_secret_encrypted_at_rest(): void
    {
        Bus::fake();
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('w', $length));
        $runId = '44444444-4444-4444-8444-444444444444';
        $payload = [...$this->validPayload($runId), 'webhook_url' => 'https://hooks.example.test/slate'];
        $credential = $this->mintCredential(['abilities' => ['consume']]);
        app()->instance(HostnameResolver::class, new ApiHostnameResolver(['93.184.216.34']));

        try {
            $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);
        } finally {
            Str::createRandomStringsNormally();
        }

        $secret = str_repeat('w', 64);
        $response->assertAccepted()->assertExactJson(['id' => $runId, 'webhook_secret' => $secret]);
        $run = Run::query()->findOrFail($runId);
        self::assertSame('https://hooks.example.test/slate', $run->webhook_url);
        self::assertSame($secret, $run->webhook_secret);
        self::assertArrayNotHasKey('webhook_url', $run->toArray());
        self::assertArrayNotHasKey('webhook_secret', $run->toArray());
        self::assertNotSame(
            'https://hooks.example.test/slate',
            DB::table('runs')->where('id', $runId)->value('webhook_url'),
        );
        self::assertNotSame($secret, DB::table('runs')->where('id', $runId)->value('webhook_secret'));
    }

    public function test_submit_rejects_non_https_webhook_url_with_typed_reason(): void
    {
        Bus::fake();
        $runId = '49494949-4949-4949-8949-494949494949';
        $payload = [...$this->validPayload($runId), 'webhook_url' => 'http://hooks.example.test/slate'];
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', RunApiErrorReason::MalformedRecipe->value)
            ->assertJsonValidationErrors(['webhook_url']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
    }

    public function test_submit_rejects_metadata_ip_webhook_destination(): void
    {
        Bus::fake();
        $runId = '50505050-5050-4050-8050-505050505050';
        $payload = [...$this->validPayload($runId), 'webhook_url' => 'https://169.254.169.254/latest/meta-data'];
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', RunApiErrorReason::InvalidWebhookDestination->value)
            ->assertJsonValidationErrors(['webhook_url']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_submit_rejects_webhook_hostname_resolving_to_private_address(): void
    {
        Bus::fake();
        app()->instance(HostnameResolver::class, new ApiHostnameResolver(['10.0.0.1']));
        $runId = '51515151-5151-4151-8151-515151515151';
        $payload = [...$this->validPayload($runId), 'webhook_url' => 'https://internal.example.test/slate'];
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', RunApiErrorReason::InvalidWebhookDestination->value)
            ->assertJsonValidationErrors(['webhook_url']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_submit_rejects_more_than_49_operations_with_typed_reason_and_no_work(): void
    {
        Bus::fake();
        $runId = '52525252-5252-4252-8252-525252525252';
        $payload = $this->validPayload($runId);
        $payload['recipe']['operations'] = array_fill(0, 50, $payload['recipe']['operations'][0]);
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', InputRejectionReason::OperationCountExceeded->value)
            ->assertJsonValidationErrors(['recipe.operations']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_submit_rejects_more_than_4900_total_output_seconds_with_typed_reason(): void
    {
        Bus::fake();
        $runId = '53535353-5353-4353-8353-535353535353';
        $payload = $this->validPayload($runId);
        $payload['recipe']['operations'][0]['duration_microseconds'] = 4_901_000_000;
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', InputRejectionReason::TotalOutputDurationExceeded->value)
            ->assertJsonValidationErrors(['recipe.operations']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_submit_rejects_recipe_requiring_more_than_100_queued_jobs(): void
    {
        Bus::fake();
        $runId = '54545454-5454-4454-8454-545454545454';
        $payload = $this->validPayload($runId);
        $payload['recipe']['operations'][0]['duration_microseconds'] = 4_851_000_000;
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', InputRejectionReason::RenderJobCountExceeded->value)
            ->assertJsonValidationErrors(['recipe.operations']);
        self::assertFalse(Run::query()->whereKey($runId)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_status_returns_explicit_shape_without_recipe(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Failed,
            'trust' => RunTrust::TrustedOrigin,
            'rejection_reason' => InputRejectionReason::ReferencedMedia,
        ]);
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id);

        $response->assertOk()->assertExactJson([
            'id' => $run->id,
            'status' => 'failed',
            'trust' => 'trusted-origin',
            'rejection_reason' => 'referenced_media',
        ]);
    }

    public function test_status_returns_404_for_a_foreign_bfc_subject(): void
    {
        $run = Run::factory()->create(['owner_subject_ref' => 'tenant-a']);
        $credential = $this->mintCredential([
            'abilities' => ['consume'],
            'subject_ref' => 'tenant-b',
        ]);

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/runs/'.$run->id);

        $response->assertNotFound()->assertExactJson([
            'message' => 'The run was not found.',
            'reason' => RunApiErrorReason::RunNotFound->value,
        ]);
    }

    #[DataProvider('duplicateStatusProvider')]
    public function test_duplicate_submit_returns_typed_409_without_dispatching(RunStatus $status): void
    {
        $runId = '55555555-5555-4555-8555-555555555555';
        $payload = $this->validPayload($runId);
        $owner = new Subject(SubjectType::Application, 'duplicate-owner');
        $run = Run::createWithRecipe(
            $runId,
            RunRecipe::fromArray($payload['recipe'], RunObjectKeys::prefix($runId)),
            $owner,
        );

        if ($status !== RunStatus::Queued) {
            $run->transitionTo(RunStatus::Running);
        }

        if ($status === RunStatus::Succeeded) {
            $run->transitionTo(RunStatus::Succeeded);
        }

        Bus::fake();
        $credential = $this->mintCredential([
            'abilities' => ['consume'],
            'subject_ref' => $owner->ref,
        ]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);

        $response->assertConflict()->assertExactJson([
            'message' => 'The run id has already been submitted.',
            'reason' => RunApiErrorReason::RunIdConflict->value,
        ]);
        Bus::assertNothingDispatched();
        self::assertSame($status, $run->fresh()->status);
    }

    public function test_uniqueness_race_returns_typed_409_without_dispatching(): void
    {
        Bus::fake();
        $runId = '56565656-5656-4656-8656-565656565656';
        $payload = $this->validPayload($runId);
        $credential = $this->mintCredential(['abilities' => ['consume']]);
        $eventName = 'eloquent.creating: '.Run::class;
        $dispatcher = Run::getEventDispatcher();
        self::assertNotNull($dispatcher);
        $dispatcher->listen($eventName, static function (Run $run) use ($payload): void {
            DB::table('runs')->insert([
                'id' => $run->id,
                'recipe' => json_encode($payload['recipe'], JSON_THROW_ON_ERROR),
                'owner_subject_type' => SubjectType::Application->value,
                'owner_subject_ref' => 'racing-owner',
                'trust' => RunTrust::Untrusted->value,
                'status' => RunStatus::Queued->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload);
        } finally {
            $dispatcher->forget($eventName);
        }

        $response->assertConflict()->assertExactJson([
            'message' => 'The run id has already been submitted.',
            'reason' => RunApiErrorReason::RunIdConflict->value,
        ]);
        Bus::assertNothingDispatched();
        self::assertSame(1, Run::query()->whereKey($runId)->count());
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_each_run_endpoint_returns_401_without_a_credential(string $method, string $path): void
    {
        $run = Run::factory()->create();
        $submitRunId = '45454545-4545-4545-8545-454545454545';

        $response = $this->json($method, str_replace('{run}', $run->id, $path), $this->validPayload($submitRunId));

        $response->assertUnauthorized();
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_each_run_endpoint_returns_403_without_consume_ability(string $method, string $path): void
    {
        $run = Run::factory()->create();
        $submitRunId = '46464646-4646-4646-8646-464646464646';
        $credential = $this->mintCredential(['abilities' => ['credential:read']]);

        $response = $this->actingAsCredential($credential)
            ->json($method, str_replace('{run}', $run->id, $path), $this->validPayload($submitRunId));

        $response->assertForbidden();
    }

    public function test_submit_poll_and_result_use_a_worked_database_queue_run(): void
    {
        $this->travelTo('2026-09-05 12:00:00');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 180);
        config()->set('slate.storage.allow_host_local_fanout', true);
        Storage::fake();
        app()->instance(Driver::class, new DatabaseQueueRunDriver);
        $runId = '47474747-4747-4747-8747-474747474747';
        $payload = $this->validPayload($runId);
        $operation = $payload['recipe']['operations'][0];
        Storage::disk()->put($operation['image_object_key'], 'image');
        Storage::disk()->put($operation['audio_object_key'], 'audio');
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        $this->actingAsCredential($credential)->postJson('/api/v1/runs', $payload)
            ->assertAccepted()
            ->assertExactJson(['id' => $runId]);
        $this->getJson('/api/v1/runs/'.$runId)->assertOk()->assertJsonPath('status', 'running');
        self::assertInstanceOf(DatabaseQueue::class, resolve('queue')->connection());
        self::assertSame(1, DB::table('jobs')->where('queue', 'default')->count());

        $worker = resolve('queue.worker');
        self::assertInstanceOf(Worker::class, $worker);
        $options = new WorkerOptions(sleep: 0, timeout: 170, maxTries: 1, force: true);
        $worker->runNextJob('database', 'default', $options);
        self::assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $worker->runNextJob('database', 'default', $options);

        self::assertSame(0, DB::table('jobs')->where('queue', 'default')->count());
        $this->getJson('/api/v1/runs/'.$runId)->assertOk()->assertJsonPath('status', 'succeeded');
        $resultKey = RunObjectKeys::operationOutput($runId, 0);
        self::assertTrue(Storage::disk()->exists($resultKey->value()));
        $objectStorage = $this->mock(ObjectStorage::class);
        $objectStorage->shouldReceive('temporaryUrl')
            ->once()
            ->withArgs(static fn (ObjectKey $key, mixed $expiration): bool => $key->value() === $resultKey->value()
                && $expiration->toISOString() === '2026-09-05T12:15:00.000000Z')
            ->andReturn('https://bucket.example.test/result.mp4?signature=temporary');

        $this->getJson('/api/v1/runs/'.$runId.'/result')->assertOk()->assertExactJson([
            'id' => $runId,
            'expires_at' => '2026-09-05T12:15:00.000000Z',
            'results' => [[
                'operation_index' => 0,
                'url' => 'https://bucket.example.test/result.mp4?signature=temporary',
            ]],
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function protectedEndpointProvider(): array
    {
        return [
            'submit' => ['POST', '/api/v1/runs'],
            'status' => ['GET', '/api/v1/runs/{run}'],
            'result' => ['GET', '/api/v1/runs/{run}/result'],
        ];
    }

    /** @return array<string, array{RunStatus}> */
    public static function duplicateStatusProvider(): array
    {
        return [
            'queued' => [RunStatus::Queued],
            'running' => [RunStatus::Running],
            'succeeded' => [RunStatus::Succeeded],
        ];
    }

    /** @return array{id: string, recipe: array{operations: list<array<string, mixed>>}} */
    private function validPayload(string $runId): array
    {
        $prefix = RunObjectKeys::prefix($runId);

        return [
            'id' => $runId,
            'recipe' => [
                'operations' => [[
                    'operation' => 'video.still_clip',
                    'image_object_key' => $prefix.'/inputs/0000/image.ppm',
                    'audio_object_key' => $prefix.'/inputs/0000/audio.mp3',
                    'width' => 1536,
                    'height' => 1024,
                    'frames_per_second' => 24,
                    'duration_microseconds' => 1_000_000,
                    'fit' => 'contain_pad',
                    'pad_color' => 'black',
                    'supersample' => 1,
                    'quality_tier' => 'delivery',
                    'audio_bitrate' => '192k',
                ]],
            ],
        ];
    }
}
