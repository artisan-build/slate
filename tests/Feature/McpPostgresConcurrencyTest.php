<?php

declare(strict_types=1);

use App\Models\McpIdempotencyClaim;
use App\Models\Run;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Mcp\McpWriteService;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses()->group('pgsql');

/**
 * @param  list<array<string, mixed>>  $payloads
 * @return list<array<string, mixed>>
 */
function runMcpPostgresRace(string $operation, array $payloads): array
{
    $barrierDirectory = storage_path('framework/testing/mcp-pg-'.Str::uuid());
    File::makeDirectory($barrierDirectory, 0755, true);
    $processes = [];
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        'DB_URL' => '',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'NIGHTWATCH_ENABLED' => 'false',
    ];

    try {
        foreach ($payloads as $index => $payload) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Fixtures/McpConcurrentWrite.php'),
                $operation,
                $barrierDirectory,
                (string) $index,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], base_path(), $environment);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 10;

        while (count(File::glob($barrierDirectory.'/*.ready')) !== count($processes)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrent MCP processes did not reach the barrier.');
            }

            usleep(10_000);
        }

        File::put($barrierDirectory.'/release', 'release');

        return array_map(function (Process $process): array {
            $exitCode = $process->wait();

            expect($exitCode)->toBe(0, $process->getErrorOutput());

            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result)->toBeArray();

            return $result;
        }, $processes);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        File::deleteDirectory($barrierDirectory);
    }
}

test('postgresql serializes simultaneous source and run submissions without duplicate dispatch', function (): void {
    if (env('MCP_POSTGRES_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Set MCP_POSTGRES_CONCURRENCY=1 on a dedicated PostgreSQL test database.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);

    $sourcePayload = [
        'idempotency_key' => 'postgres-source-race',
        'url' => 'https://media.example.test/race.mp4',
    ];
    $sourceResults = runMcpPostgresRace('source', [
        [...$sourcePayload, 'source_id' => '66666666-6666-4666-8666-666666666661'],
        [...$sourcePayload, 'source_id' => '66666666-6666-4666-8666-666666666662'],
    ]);
    $sourceOutcomes = array_column($sourceResults, 'outcome');
    sort($sourceOutcomes);

    expect($sourceOutcomes)->toBe(['accepted', 'in_progress'])
        ->and(Source::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);

    DB::table('jobs')->delete();
    $owner = [
        'owner_subject_type' => SubjectType::Installation,
        'owner_subject_ref' => 'postgres-mcp-owner',
        'status' => SourceStatus::Ready,
    ];
    $image = Source::factory()->create($owner);
    $audio = Source::factory()->create($owner);
    $runId = '77777777-7777-4777-8777-777777777777';
    $runPayload = [
        'idempotency_key' => 'postgres-run-race',
        'run_id' => $runId,
        'recipe' => [
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
        ],
    ];
    $runResults = runMcpPostgresRace('run', [$runPayload, $runPayload]);
    $runOutcomes = array_column($runResults, 'outcome');
    sort($runOutcomes);

    expect($runOutcomes)->toBe(['accepted', 'in_progress'])
        ->and(Run::query()->whereKey($runId)->count())->toBe(1)
        ->and(DB::table('job_batches')->count())->toBe(1);

    $knownAcceptance = resolve(McpWriteService::class)->execute(
        new Subject(SubjectType::Installation, 'postgres-mcp-owner'),
        'create_run',
        'postgres-known-acceptance',
        ['run_id' => 'known-acceptance'],
        'run',
        'known-acceptance',
        static function (): array {
            DB::afterCommit(static function (): never {
                throw new RuntimeException('post-acceptance dispatch failed');
            });

            return ['id' => 'known-acceptance'];
        },
    );

    expect($knownAcceptance->outcome)->toBe('accepted')
        ->and(McpIdempotencyClaim::query()->where('idempotency_key', 'postgres-known-acceptance')->value('status'))
        ->toBe('accepted');
});
