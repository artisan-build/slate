<?php

declare(strict_types=1);

use App\Jobs\IngestRemoteSource;
use App\Mcp\Servers\SlateServer;
use App\Models\McpIdempotencyClaim;
use App\Models\Run;
use App\Models\RunStatus;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Mcp\McpWriteRefused;
use App\Slate\Mcp\McpWriteService;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Storage\ScratchPath;
use App\Slate\Webhook\HostnameResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

uses(LazilyRefreshDatabase::class, ContractAssertions::class, WithCredentials::class);

beforeEach(function (): void {
    app()->instance(IngestStorage::class, Mockery::mock(IngestStorage::class));
});

function slateMcpCredential(
    SubjectType $subjectType = SubjectType::Installation,
    string $subjectRef = 'mcp-owner',
): MintedTestCredential {
    return test()->mintCredential([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef,
        'user_id' => null,
    ]);
}

/** @param array<string, mixed> $arguments */
function slateMcpRpc(
    string $path,
    MintedTestCredential $credential,
    string $method,
    array $arguments = [],
    int|string $id = 1,
): TestResponse {
    $params = $method === 'tools/call'
        ? ['name' => $arguments['tool'] ?? '', 'arguments' => $arguments['arguments'] ?? []]
        : [];

    return test()->withHeader('Authorization', $credential->bearerHeader())->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ]);
}

/** @param array<string, mixed> $arguments */
function slateMcpTool(
    string $path,
    MintedTestCredential $credential,
    string $tool,
    array $arguments,
    int|string $id = 1,
): TestResponse {
    return slateMcpRpc($path, $credential, 'tools/call', [
        'tool' => $tool,
        'arguments' => $arguments,
    ], $id);
}

/** @param array<string, mixed> $schema */
function assertSlateSchemaClosed(array $schema): void
{
    if (($schema['type'] ?? null) === 'object') {
        expect($schema['additionalProperties'] ?? null)->toBeFalse();
    }

    foreach (($schema['properties'] ?? []) as $property) {
        if (is_array($property)) {
            assertSlateSchemaClosed($property);
        }
    }

    foreach (($schema['anyOf'] ?? []) as $option) {
        if (is_array($option)) {
            assertSlateSchemaClosed($option);
        }
    }

    if (is_array($schema['items'] ?? null)) {
        assertSlateSchemaClosed($schema['items']);
    }
}

/** @return array<string, mixed> */
function slateDeliveryRecipe(string $ownerRef = 'mcp-owner'): array
{
    $owner = [
        'owner_subject_type' => SubjectType::Installation,
        'owner_subject_ref' => $ownerRef,
        'status' => SourceStatus::Ready,
    ];
    $image = Source::factory()->create($owner);
    $audio = Source::factory()->create($owner);

    return [
        'delivery' => [
            'pages' => [
                ['image' => $image->id, 'audio' => $audio->id, 'duration' => 3.0],
                ['image' => $image->id, 'audio' => $audio->id, 'duration' => 3.0],
            ],
            'transitions' => ['type' => 'fade', 'duration' => 1.0],
            'quality_tier' => 'delivery',
            'width' => 1536,
            'height' => 1024,
        ],
    ];
}

function slateMcpAssertion(string $subject = 'delegated-mcp-operator'): string
{
    if (! app()->bound('slate.testing.mcp-signing-key')) {
        foreach (range(1, 16) as $ignored) {
            $secret = AsymmetricSecretKey::generate(new Version4);

            if (strlen($secret->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $keyring = new ConsoleKeyring;
                $keyring->activate($keyring->add('slate-mcp-test', $secret->getPublicKey()->toHexString())->key_id);
                app()->instance('slate.testing.mcp-signing-key', $secret);
                break;
            }
        }
    }

    $secret = resolve('slate.testing.mcp-signing-key');
    expect($secret)->toBeInstanceOf(AsymmetricSecretKey::class);
    $now = now()->toImmutable();

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims([
            'iss' => 'https://scalpels.test',
            'sub' => $subject,
            'aud' => 'https://slate.test',
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'mint_'.bin2hex(random_bytes(8)),
            'display_name' => 'Delegated MCP Operator',
            'role' => 'member',
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => 'slate-mcp-test'])
        ->toString();
}

final readonly class McpPublicHostnameResolver implements HostnameResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses = ['93.184.216.34']) {}

    public function resolve(string $hostname): array
    {
        return $this->addresses;
    }
}

final class McpResultStorage implements ObjectStorage
{
    /** @var list<string> */
    public array $temporaryUrlKeys = [];

    public string $url = 'https://bucket.example.test/result.mp4?signature=temporary';

    public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void {}

    public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void {}

    public function exists(ObjectKey $objectKey): bool
    {
        return true;
    }

    public function delete(ObjectKey $objectKey): void {}

    public function temporaryUrl(ObjectKey $objectKey, DateTimeInterface $expiration): string
    {
        $this->temporaryUrlKeys[] = $objectKey->value();

        return $this->url;
    }

    public function keysWithinPrefix(string $prefix): array
    {
        return [];
    }
}

test('the server advertises exactly the five classified effect-scoped tools through the correct doors', function (): void {
    $credential = slateMcpCredential();

    $this->assertBuiltForCloudMcpDelegatedTools(SlateServer::class);
    $this->assertBuiltForCloudMcpProductAdmission();

    $metadata = $this->getJson('/bfc/meta')->assertOk();
    expect($metadata->json('capabilities'))
        ->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($metadata->json('endpoints'))->toBe([
            'mcp' => '/mcp',
            'mcp_write' => '/mcp-write',
        ]);

    $readTools = slateMcpRpc('/mcp', $credential, 'tools/list')->assertOk()->json('result.tools');
    $writeTools = slateMcpRpc('/mcp-write', $credential, 'tools/list')->assertOk()->json('result.tools');
    expect(array_column($readTools, 'name'))->toBe(['source_status', 'run_status', 'run_result_url'])
        ->and(array_column($writeTools, 'name'))->toBe([
            'source_status',
            'run_status',
            'run_result_url',
            'create_remote_source',
            'create_run',
        ]);

    foreach ($writeTools as $tool) {
        assertSlateSchemaClosed($tool['inputSchema']);
        expect($tool['_meta']['classification'])->toBeIn(['metadata', 'content'])
            ->and($tool['_meta']['effect'])->toBeIn(['read', 'write'])
            ->and($tool['annotations'])->not->toBe([]);
    }

    slateMcpTool('/mcp', $credential, 'create_remote_source', [
        'idempotency_key' => 'read-door-refusal',
        'url' => 'https://media.example.test/video.mp4',
    ])->assertStatus(400)->assertExactJson([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => RequestEffectCeiling::REFUSAL_CODE,
            'message' => RequestEffectCeiling::REFUSAL_MESSAGE,
        ],
    ]);
});

test('status reads preserve subject type and ref isolation and return only frozen fields', function (): void {
    $credential = slateMcpCredential(SubjectType::Installation, 'same-ref');
    $foreignTypeCredential = slateMcpCredential(SubjectType::UserPrincipal, 'same-ref');
    $source = Source::factory()->create([
        'owner_subject_type' => SubjectType::Installation,
        'owner_subject_ref' => 'same-ref',
        'status' => SourceStatus::Ready,
        'bytes' => 9000,
        'content_type' => 'video/mp4',
    ]);
    $run = Run::factory()->create([
        'owner_subject_type' => SubjectType::Installation,
        'owner_subject_ref' => 'same-ref',
        'status' => RunStatus::Failed,
    ]);

    slateMcpTool('/mcp', $credential, 'source_status', ['source_id' => $source->id])
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'id' => $source->id,
            'status' => 'ready',
            'rejection_reason' => null,
        ]);
    slateMcpTool('/mcp', $credential, 'run_status', ['run_id' => $run->id])
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'id' => $run->id,
            'status' => 'failed',
            'trust' => 'untrusted',
            'rejection_reason' => null,
        ]);

    $foreign = slateMcpTool('/mcp', $foreignTypeCredential, 'source_status', ['source_id' => $source->id])
        ->assertOk();
    $missing = slateMcpTool('/mcp', $foreignTypeCredential, 'source_status', [
        'source_id' => '11111111-1111-4111-8111-111111111111',
    ])->assertOk();
    expect($foreign->getContent())->toBe($missing->getContent())
        ->and($foreign->json('result.isError'))->toBeTrue()
        ->and($foreign->json('result.content.0.text'))->toBe('not_found');
});

test('a verified delegated assertion retains a type-qualified owner distinct from credentials', function (): void {
    config([
        'built-for-cloud.console.issuer' => 'https://scalpels.test',
        'built-for-cloud.console.audience' => 'https://slate.test',
    ]);
    $missingId = '88888888-8888-4888-8888-888888888888';

    $this->withHeader('Authorization', 'Bearer '.slateMcpAssertion())->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'source_status', 'arguments' => ['source_id' => $missingId]],
    ])->assertOk()->assertJsonPath('result.content.0.text', 'not_found');

    $actor = DelegatedActor::query()->sole();
    $ownerRef = DelegatedActor::class.'#'.$actor->getAuthIdentifier();
    $source = Source::factory()->create([
        'owner_subject_type' => SubjectType::ExternalConsumer,
        'owner_subject_ref' => $ownerRef,
        'status' => SourceStatus::Ready,
    ]);

    $this->withHeader('Authorization', 'Bearer '.slateMcpAssertion())->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'source_status', 'arguments' => ['source_id' => $source->id]],
    ])->assertOk()->assertJsonPath('result.structuredContent.id', $source->id);

    expect($ownerRef)->toStartWith(DelegatedActor::class.'#bfc-console:');
});

test('result URL signs only the final object for an owned succeeded run with the configured TTL', function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    config()->set('slate.storage.allow_host_local_fanout', true);
    Storage::fake();
    $storage = new McpResultStorage;
    app()->instance(ObjectStorage::class, $storage);
    $credential = slateMcpCredential();
    $run = Run::factory()->create([
        'owner_subject_ref' => 'mcp-owner',
        'status' => RunStatus::Succeeded,
    ]);

    slateMcpTool('/mcp', $credential, 'run_result_url', ['run_id' => $run->id])
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'id' => $run->id,
            'expires_at' => '2026-09-30T12:15:00.000000Z',
            'url' => $storage->url,
        ]);

    expect($storage->temporaryUrlKeys)->toBe([RunObjectKeys::deliveryOutput($run->id)->value()]);
});

test('result URL refuses foreign and incomplete runs before touching storage and fails a host-local guard closed', function (): void {
    config()->set('slate.storage.allow_host_local_fanout', false);
    Storage::fake();
    $storage = new McpResultStorage;
    app()->instance(ObjectStorage::class, $storage);
    $credential = slateMcpCredential();
    $pending = Run::factory()->create([
        'owner_subject_ref' => 'mcp-owner',
        'status' => RunStatus::Queued,
    ]);
    $foreign = Run::factory()->create([
        'owner_subject_ref' => 'other-owner',
        'status' => RunStatus::Succeeded,
    ]);
    $succeeded = Run::factory()->create([
        'owner_subject_ref' => 'mcp-owner',
        'status' => RunStatus::Succeeded,
    ]);

    slateMcpTool('/mcp', $credential, 'run_result_url', ['run_id' => $pending->id])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'result_not_ready');
    slateMcpTool('/mcp', $credential, 'run_result_url', ['run_id' => $foreign->id])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'not_found');
    slateMcpTool('/mcp', $credential, 'run_result_url', ['run_id' => $succeeded->id])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'unavailable');

    expect($storage->temporaryUrlKeys)->toBe([]);
});

test('remote source writes are scoped idempotent SSRF-checked and dispatch once', function (): void {
    Queue::fake([IngestRemoteSource::class]);
    app()->instance(HostnameResolver::class, new McpPublicHostnameResolver);
    $credential = slateMcpCredential();
    $arguments = [
        'idempotency_key' => 'source-key',
        'url' => 'https://media.example.test/video.mp4',
    ];

    $first = slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)->assertOk();
    $sourceId = $first->json('result.structuredContent.id');
    expect($first->json('result.structuredContent.outcome'))->toBe('accepted')
        ->and($sourceId)->toBeString();

    slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'outcome' => 'already_accepted',
            'id' => $sourceId,
        ]);
    slateMcpTool('/mcp-write', $credential, 'create_remote_source', [
        ...$arguments,
        'url' => 'https://media.example.test/other.mp4',
    ])->assertOk()->assertJsonPath('result.structuredContent', [
        'outcome' => 'refused',
        'reason' => 'idempotency_key_reused',
    ]);

    expect(Source::query()->count())->toBe(1)
        ->and(Source::query()->sole()->owner_subject_type)->toBe(SubjectType::Installation)
        ->and(Source::query()->sole()->owner_subject_ref)->toBe('mcp-owner');
    Queue::assertPushed(IngestRemoteSource::class, 1);

    $other = slateMcpCredential(SubjectType::ExternalConsumer, 'other-owner');
    slateMcpTool('/mcp-write', $other, 'create_remote_source', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent.outcome', 'accepted');
    expect(Source::query()->count())->toBe(2);
    Queue::assertPushed(IngestRemoteSource::class, 2);
});

test('unsafe remote source refusal is terminal and never upgrades after DNS changes', function (): void {
    Queue::fake([IngestRemoteSource::class]);
    app()->instance(HostnameResolver::class, new McpPublicHostnameResolver(['10.0.0.1']));
    $credential = slateMcpCredential();
    $arguments = [
        'idempotency_key' => 'unsafe-source-key',
        'url' => 'https://internal.example.test/video.mp4',
    ];

    slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'outcome' => 'refused',
            'reason' => 'unsafe_source_url',
        ]);

    app()->instance(HostnameResolver::class, new McpPublicHostnameResolver);
    slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'outcome' => 'refused',
            'reason' => 'unsafe_source_url',
        ]);

    expect(Source::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('pre-claim persistence failures return bounded errors and identical retries can be accepted', function (): void {
    Queue::fake([IngestRemoteSource::class]);
    app()->instance(HostnameResolver::class, new McpPublicHostnameResolver);
    $credential = slateMcpCredential();
    $event = 'eloquent.creating: '.McpIdempotencyClaim::class;
    $dispatcher = McpIdempotencyClaim::getEventDispatcher();
    expect($dispatcher)->not->toBeNull();
    $sourceArguments = [
        'idempotency_key' => 'source-pre-claim-failure',
        'url' => 'https://media.example.test/pre-claim.mp4',
    ];

    $dispatcher->listen($event, static function (McpIdempotencyClaim $claim): never {
        throw new RuntimeException('raw source persistence failure');
    });

    try {
        $sourceFailure = slateMcpTool('/mcp-write', $credential, 'create_remote_source', $sourceArguments);
    } finally {
        $dispatcher->forget($event);
    }

    $sourceFailure
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'unavailable')
        ->assertJsonMissingPath('result.structuredContent');
    expect($sourceFailure->getContent())->not->toContain('raw source persistence failure')
        ->and(McpIdempotencyClaim::query()->where('idempotency_key', 'source-pre-claim-failure')->count())->toBe(0)
        ->and(Source::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    slateMcpTool('/mcp-write', $credential, 'create_remote_source', $sourceArguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent.outcome', 'accepted');
    expect(Source::query()->count())->toBe(1);
    Queue::assertPushed(IngestRemoteSource::class, 1);

    Bus::fake();
    $runId = '99999999-9999-4999-8999-999999999999';
    $runArguments = [
        'idempotency_key' => 'run-pre-claim-failure',
        'run_id' => $runId,
        'recipe' => slateDeliveryRecipe(),
    ];

    $dispatcher->listen($event, static function (McpIdempotencyClaim $claim): never {
        throw new RuntimeException('raw run persistence failure');
    });

    try {
        $runFailure = slateMcpTool('/mcp-write', $credential, 'create_run', $runArguments);
    } finally {
        $dispatcher->forget($event);
    }

    $runFailure
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'unavailable')
        ->assertJsonMissingPath('result.structuredContent');
    expect($runFailure->getContent())->not->toContain('raw run persistence failure')
        ->and(McpIdempotencyClaim::query()->where('idempotency_key', 'run-pre-claim-failure')->count())->toBe(0)
        ->and(Run::query()->whereKey($runId)->count())->toBe(0);
    Bus::assertNothingBatched();

    slateMcpTool('/mcp-write', $credential, 'create_run', $runArguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', ['outcome' => 'accepted', 'id' => $runId]);
    expect(Run::query()->whereKey($runId)->count())->toBe(1);
    Bus::assertBatchCount(1);
});

test('create run accepts only typed delivery and highlights recipes and dispatches once', function (): void {
    Bus::fake();
    $credential = slateMcpCredential();
    $runId = '22222222-2222-4222-8222-222222222222';
    $arguments = [
        'idempotency_key' => 'run-key',
        'run_id' => $runId,
        'recipe' => slateDeliveryRecipe(),
    ];

    slateMcpTool('/mcp-write', $credential, 'create_run', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', ['outcome' => 'accepted', 'id' => $runId]);
    slateMcpTool('/mcp-write', $credential, 'create_run', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', ['outcome' => 'already_accepted', 'id' => $runId]);

    $run = Run::query()->findOrFail($runId);
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->owner_subject_ref)->toBe('mcp-owner')
        ->and($run->recipe()->operations)->toHaveCount(2);
    Bus::assertBatchCount(1);

    $invalid = $arguments;
    $invalid['idempotency_key'] = 'invalid-run-key';
    $invalid['run_id'] = '33333333-3333-4333-8333-333333333333';
    $invalid['recipe'] = ['operations' => [['command' => ['ffmpeg', '-i', 'secret']]]];
    slateMcpTool('/mcp-write', $credential, 'create_run', $invalid)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'outcome' => 'refused',
            'reason' => 'malformed_recipe',
        ]);
    expect(Run::query()->count())->toBe(1);
    Bus::assertBatchCount(1);

    $source = Source::factory()->create([
        'owner_subject_ref' => 'mcp-owner',
        'status' => SourceStatus::Ready,
    ]);
    $highlightsRunId = '44444444-4444-4444-8444-444444444444';
    slateMcpTool('/mcp-write', $credential, 'create_run', [
        'idempotency_key' => 'highlights-key',
        'run_id' => $highlightsRunId,
        'recipe' => [
            'highlights' => [
                'source' => $source->id,
                'clips' => [
                    ['start' => 0.0, 'end' => 2.0],
                    ['start' => 3.0, 'end' => 5.0],
                ],
                'transitions' => ['type' => 'fade', 'duration' => 1.0],
                'quality_tier' => 'delivery',
            ],
        ],
    ])->assertOk()->assertJsonPath('result.structuredContent.outcome', 'accepted');
    expect(Run::query()->findOrFail($highlightsRunId)->recipe()->operations)->toHaveCount(2);
});

test('create run terminally refuses representative null non-list and unknown nested recipe shapes', function (): void {
    Bus::fake();
    $credential = slateMcpCredential();
    $validRecipe = slateDeliveryRecipe();
    $page = $validRecipe['delivery']['pages'][0];
    $recipes = [
        ['delivery' => null],
        ['delivery' => [...$validRecipe['delivery'], 'pages' => ['first' => $page, 'second' => $page]]],
        ['delivery' => [...$validRecipe['delivery'], 'encoder' => ['arguments' => ['-i', 'secret']]]],
        ['operations' => [['command' => ['ffmpeg', '-i', 'secret']]]],
    ];

    foreach ($recipes as $index => $recipe) {
        slateMcpTool('/mcp-write', $credential, 'create_run', [
            'idempotency_key' => 'malformed-'.$index,
            'run_id' => Str::uuid()->toString(),
            'recipe' => $recipe,
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.outcome', 'refused')
            ->assertJsonPath('result.structuredContent.reason', 'malformed_recipe');
    }

    expect(Run::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

test('write outcomes cover live execution terminal refusal uncertainty recovery and known acceptance', function (): void {
    $service = resolve(McpWriteService::class);
    $owner = new Subject(SubjectType::Installation, 'service-owner');
    $nested = null;
    $first = $service->execute(
        $owner,
        'create_remote_source',
        'live-key',
        ['url' => 'https://example.test/live'],
        'source',
        '55555555-5555-4555-8555-555555555555',
        function () use ($service, $owner, &$nested): array {
            $nested = $service->execute(
                $owner,
                'create_remote_source',
                'live-key',
                ['url' => 'https://example.test/live'],
                'source',
                'ignored-on-retry',
                static fn (): array => ['id' => 'must-not-run'],
            );

            return ['id' => '55555555-5555-4555-8555-555555555555'];
        },
    );
    expect($nested?->outcome)->toBe('in_progress')
        ->and($first->outcome)->toBe('accepted');

    $refusedExecutions = 0;
    $refused = function () use ($service, $owner, &$refusedExecutions) {
        return $service->execute(
            $owner,
            'create_run',
            'refused-key',
            ['run_id' => 'refused'],
            'run',
            'refused',
            function () use (&$refusedExecutions): never {
                $refusedExecutions++;
                throw new McpWriteRefused('malformed_recipe');
            },
        );
    };
    expect($refused()->outcome)->toBe('refused')
        ->and($refused()->outcome)->toBe('refused')
        ->and($refusedExecutions)->toBe(1);

    $unknownExecutions = 0;
    $unknown = function () use ($service, $owner, &$unknownExecutions) {
        return $service->execute(
            $owner,
            'create_run',
            'unknown-key',
            ['run_id' => 'unknown'],
            'run',
            'unknown',
            function () use (&$unknownExecutions): never {
                $unknownExecutions++;
                throw new RuntimeException('unexposed failure detail');
            },
        );
    };
    expect($unknown()->outcome)->toBe('outcome_unknown')
        ->and($unknown()->outcome)->toBe('outcome_unknown')
        ->and($unknownExecutions)->toBe(1);

    $event = 'eloquent.updating: '.McpIdempotencyClaim::class;
    $dispatcher = McpIdempotencyClaim::getEventDispatcher();
    expect($dispatcher)->not->toBeNull();
    $dispatcher->listen($event, static function (McpIdempotencyClaim $claim): void {
        if ($claim->status === 'accepted') {
            throw new RuntimeException('settlement write failed');
        }
    });

    try {
        $settlement = $service->execute(
            $owner,
            'create_run',
            'settlement-key',
            ['run_id' => 'settlement'],
            'run',
            'settlement',
            static fn (): array => ['id' => 'settlement'],
        );
    } finally {
        $dispatcher->forget($event);
    }

    expect($settlement->outcome)->toBe('outcome_unknown')
        ->and(McpIdempotencyClaim::query()->where('idempotency_key', 'settlement-key')->value('status'))
        ->toBe('outcome_unknown')
        ->and(McpIdempotencyClaim::query()->where('status', 'in_progress')->count())->toBe(0);
});

test('compound settlement failure stays non-terminal until lease recovery durably settles uncertainty', function (): void {
    $service = resolve(McpWriteService::class);
    $owner = new Subject(SubjectType::Installation, 'compound-settlement-owner');
    $knownAcceptance = $service->execute(
        $owner,
        'create_run',
        'known-acceptance-key',
        ['run_id' => 'known-acceptance'],
        'run',
        'known-acceptance',
        static fn (): array => ['id' => 'known-acceptance'],
    );
    $event = 'eloquent.updating: '.McpIdempotencyClaim::class;
    $dispatcher = McpIdempotencyClaim::getEventDispatcher();
    expect($knownAcceptance->outcome)->toBe('accepted')
        ->and($dispatcher)->not->toBeNull();
    $dispatcher->listen($event, static function (McpIdempotencyClaim $claim): void {
        if (in_array($claim->status, ['accepted', 'outcome_unknown'], true)) {
            throw new RuntimeException('compound settlement write failed');
        }
    });
    $operationExecutions = 0;

    try {
        $first = $service->execute(
            $owner,
            'create_run',
            'compound-settlement-key',
            ['run_id' => 'compound-settlement'],
            'run',
            'compound-settlement',
            function () use (&$operationExecutions): array {
                $operationExecutions++;

                return ['id' => 'compound-settlement'];
            },
        );
        $acceptedReplay = $service->execute(
            $owner,
            'create_run',
            'known-acceptance-key',
            ['run_id' => 'known-acceptance'],
            'run',
            'known-acceptance',
            static function (): never {
                throw new RuntimeException('known acceptance was re-executed');
            },
        );
    } finally {
        $dispatcher->forget($event);
    }

    $claim = McpIdempotencyClaim::query()->where('idempotency_key', 'compound-settlement-key')->sole();
    expect($first->outcome)->toBe('in_progress')
        ->and($operationExecutions)->toBe(1)
        ->and($claim->status)->toBe('in_progress')
        ->and($claim->execution_token)->not->toBeNull()
        ->and($claim->lease_expires_at)->not->toBeNull()
        ->and($acceptedReplay->outcome)->toBe('already_accepted')
        ->and(McpIdempotencyClaim::query()->where('idempotency_key', 'known-acceptance-key')->value('status'))
        ->toBe('accepted');

    $this->travel(301)->seconds();
    $retry = function () use ($service, $owner, &$operationExecutions) {
        return $service->execute(
            $owner,
            'create_run',
            'compound-settlement-key',
            ['run_id' => 'compound-settlement'],
            'run',
            'compound-settlement',
            function () use (&$operationExecutions): array {
                $operationExecutions++;

                return ['id' => 'must-not-run'];
            },
        );
    };

    expect($retry()->outcome)->toBe('outcome_unknown')
        ->and($retry()->outcome)->toBe('outcome_unknown')
        ->and($operationExecutions)->toBe(1);
    $claim->refresh();
    expect($claim->status)->toBe('outcome_unknown')
        ->and($claim->execution_token)->toBeNull()
        ->and($claim->lease_expires_at)->toBeNull();
});

test('accepted retries never infer state from disappeared or recreated resources', function (): void {
    Queue::fake([IngestRemoteSource::class]);
    app()->instance(HostnameResolver::class, new McpPublicHostnameResolver);
    $credential = slateMcpCredential();
    $arguments = [
        'idempotency_key' => 'disappeared-source-key',
        'url' => 'https://media.example.test/disappeared.mp4',
    ];
    $first = slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)->assertOk();
    $sourceId = $first->json('result.structuredContent.id');
    Source::query()->whereKey($sourceId)->delete();
    Source::factory()->create([
        'id' => $sourceId,
        'object_key' => 'slate-sources/recreated/source',
        'owner_subject_type' => SubjectType::ExternalConsumer,
        'owner_subject_ref' => 'foreign-owner',
    ]);

    slateMcpTool('/mcp-write', $credential, 'create_remote_source', $arguments)
        ->assertOk()
        ->assertJsonPath('result.structuredContent', [
            'outcome' => 'already_accepted',
            'id' => $sourceId,
        ]);
    expect(Source::query()->count())->toBe(1);
    Queue::assertPushed(IngestRemoteSource::class, 1);
});

test('runtime refuses malformed closed inputs and maximum request id responses remain under the relay cap', function (): void {
    $credential = slateMcpCredential();

    foreach ([
        ['source_id' => null],
        ['source_id' => 123],
        ['source_id' => '11111111-1111-4111-8111-111111111111', 'extra' => true],
        ['source_id' => '11111111-1111-4111-8111-111111111111', 0 => 'numeric-key'],
        ['source_id' => str_repeat('x', 2049)],
    ] as $arguments) {
        slateMcpTool('/mcp', $credential, 'source_status', $arguments)
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'invalid_arguments');
    }

    slateMcpTool('/mcp-write', $credential, 'create_remote_source', [
        'idempotency_key' => ['not-a-string'],
        'url' => 'https://media.example.test/video.mp4',
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'invalid_arguments');

    $maximumId = str_repeat('x', AuthenticateMcp::MAX_JSON_RPC_ID_BYTES - 2);
    $response = slateMcpTool('/mcp', $credential, 'source_status', [
        'source_id' => '11111111-1111-4111-8111-111111111111',
    ], $maximumId)->assertOk()->assertJsonPath('id', $maximumId);
    expect(strlen($response->getContent()))->toBeLessThan(1_048_576);
});
