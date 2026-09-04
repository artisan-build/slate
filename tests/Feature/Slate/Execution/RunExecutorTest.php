<?php

use App\Jobs\CoarseCutVideoSource;
use App\Jobs\CompleteRun;
use App\Jobs\FinalizeRunSplitOperation;
use App\Jobs\RenderRunOperation;
use App\Jobs\RenderRunSubClip;
use App\Jobs\RenderVideoClip;
use App\Models\Run;
use App\Models\RunStatus;
use App\Services\RunCleanup;
use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Execution\CoarseCutSource;
use App\Slate\Execution\CopyConcatSubClips;
use App\Slate\Execution\ExecutorStrategy;
use App\Slate\Execution\MuxOperationAudio;
use App\Slate\Execution\PreciseVideoClip;
use App\Slate\Execution\RenderSilentSubClip;
use App\Slate\Execution\RunExecutor;
use App\Slate\Execution\SkipIfBatchUnavailable;
use App\Slate\Execution\WholeOperation;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunStillClip;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

final class RecordingRunExecutionDriver implements Driver
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @param array<string, array<string, mixed>> $probeProperties */
    public function __construct(private array $probeProperties) {}

    public function run(CompiledRecipe $recipe): DriverResult
    {
        $command = $recipe->command();
        $this->commands[] = $command;
        $output = $command[count($command) - 1];

        if (in_array('-show_format', $command, true)) {
            $properties = $this->probeProperties[basename($output)] ?? null;

            if ($properties === null) {
                throw new RuntimeException('Missing probe properties for '.basename($output));
            }

            return new DriverResult(json_encode($properties, JSON_THROW_ON_ERROR));
        }

        file_put_contents($output, 'rendered '.basename($output));

        return new DriverResult;
    }
}

/** @return array<string, mixed> */
function runExecutionProbeProperties(int $frameCount, float $durationSeconds, bool $hasAudio): array
{
    $streams = [[
        'codec_type' => 'video',
        'codec_name' => 'h264',
        'width' => 1536,
        'height' => 1024,
        'avg_frame_rate' => '24/1',
        'pix_fmt' => 'yuv420p',
        'color_range' => 'tv',
        'nb_frames' => (string) $frameCount,
    ]];

    if ($hasAudio) {
        $streams[] = ['codec_type' => 'audio', 'codec_name' => 'aac'];
    }

    return [
        'streams' => $streams,
        'format' => ['duration' => number_format($durationSeconds, 6, '.', '')],
    ];
}

function executorRecipeOperation(string $runId, int $operationIndex, int $durationMicroseconds): RunStillClip
{
    $prefix = RunObjectKeys::prefix($runId);

    return new RunStillClip(
        ObjectKey::withinPrefix($prefix, sprintf('%s/inputs/%04d/image.ppm', $prefix, $operationIndex)),
        ObjectKey::withinPrefix($prefix, sprintf('%s/inputs/%04d/audio.mp3', $prefix, $operationIndex)),
        1536,
        1024,
        24,
        $durationMicroseconds,
        VideoFit::ContainPad,
        VideoColor::Black,
        1,
        QualityTier::Delivery,
        AudioBitrate::Kbps192,
    );
}

/** @param non-empty-list<RunStillClip> $operations */
function executorRun(array $operations, string $runId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'): Run
{
    return Run::createWithRecipe($runId, new RunRecipe($operations));
}

function configureExecutorPolicy(): void
{
    config()->set('queue.default', 'executor_test');
    config()->set('queue.connections.executor_test', [
        'driver' => 'database',
        'queues' => ['render' => ['retry_after' => 180]],
    ]);
    config()->set('slate.execution.measured_realtime_rates.delivery', 0.389);
}

test('plans one padded coarse cut job per source before precise per clip fan out', function (): void {
    configureExecutorPolicy();
    $runId = '51515151-5151-4151-8151-515151515151';
    $firstSource = RunObjectKeys::input($runId, 0, 'first.mp4');
    $secondSource = RunObjectKeys::input($runId, 1, 'second.mp4');
    $run = Run::createWithRecipe($runId, new RunRecipe([
        new RunVideoClip($firstSource, 10.0, 20.0),
        new RunVideoClip($firstSource, 30.0, 36.0),
        new RunVideoClip($secondSource, 2.0, 7.0),
    ]));

    $plan = resolve(RunExecutor::class)->plan($run);
    $coarse = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof CoarseCutSource,
    ));
    $precise = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof PreciseVideoClip,
    ));

    expect($plan->strategy)->toBe(ExecutorStrategy::VideoClipFanout)
        ->and($coarse)->toHaveCount(2)
        ->and($coarse[0]->source->value())->toBe($firstSource->value())
        ->and($coarse[0]->ranges)->toHaveCount(2)
        ->and($coarse[1]->source->value())->toBe($secondSource->value())
        ->and($coarse[1]->ranges)->toHaveCount(1)
        ->and($precise)->toHaveCount(3)
        ->and($precise[0]->startMicroseconds)->toBe(5_000_000)
        ->and($precise[1]->startMicroseconds)->toBe(5_000_000)
        ->and($precise[2]->startMicroseconds)->toBe(2_000_000)
        ->and(array_map(
            static fn (ObjectKey $key): string => $key->value(),
            $plan->intermediateObjectKeys(),
        ))->toBe([
            RunObjectKeys::coarseClip($runId, 0)->value(),
            RunObjectKeys::coarseClip($runId, 1)->value(),
            RunObjectKeys::coarseClip($runId, 2)->value(),
        ]);
});

test('dispatches exactly one coarse cut queue job for each distinct source', function (): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = '52525252-5252-4252-8252-525252525252';
    $firstSource = RunObjectKeys::input($runId, 0, 'first.mp4');
    $secondSource = RunObjectKeys::input($runId, 1, 'second.mp4');
    $run = Run::createWithRecipe($runId, new RunRecipe([
        new RunVideoClip($firstSource, 10.0, 20.0),
        new RunVideoClip($firstSource, 30.0, 36.0),
        new RunVideoClip($secondSource, 2.0, 7.0),
    ]));

    resolve(RunExecutor::class)->execute($run);

    $then = null;
    Bus::assertBatched(function (PendingBatch $batch) use (&$then): bool {
        if (! $batch->jobs->every(static fn (object $job): bool => $job instanceof CoarseCutVideoSource)) {
            return false;
        }

        $then = $batch->thenCallbacks()[0] ?? null;

        return $batch->jobs->count() === 2 && count($batch->catchCallbacks()) === 1;
    });
    expect($then)->toBeCallable();
    $then(Mockery::mock(Batch::class));
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3
        && $batch->jobs->every(static fn (object $job): bool => $job instanceof RenderVideoClip)
        && count($batch->finallyCallbacks()) === 1);
    expect($run->fresh()->status)->toBe(RunStatus::Running);
});

test('executes an over budget operation as bucket-backed silent slices then copy concats and muxes audio once', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $recipe = executorRecipeOperation($runId, 0, 120_000_000);
    $run = executorRun([$recipe], $runId);
    $run->transitionTo(RunStatus::Running);
    Storage::disk()->put($recipe->image->value(), 'image');
    Storage::disk()->put($recipe->audio->value(), 'audio');
    $plan = resolve(RunExecutor::class)->plan($run);
    $subClips = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof RenderSilentSubClip,
    ));
    $concat = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof CopyConcatSubClips,
    ))[0];
    $mux = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof MuxOperationAudio,
    ))[0];
    $driver = new RecordingRunExecutionDriver([
        'subclip-0000.mp4' => runExecutionProbeProperties(960, 40.0, false),
        'subclip-0001.mp4' => runExecutionProbeProperties(960, 40.0, false),
        'subclip-0002.mp4' => runExecutionProbeProperties(960, 40.0, false),
        'silent.mp4' => runExecutionProbeProperties(2880, 120.0, false),
        'output.mp4' => runExecutionProbeProperties(2880, 120.0, true),
    ]);
    $objectStorage = resolve(ObjectStorage::class);
    $jobScratch = resolve(JobScratch::class);
    $compiler = RecipeCompiler::fromSystem();
    $storageGuard = resolve(FanoutStorageGuard::class);
    $serializedJobs = [];

    foreach ($subClips as $subClip) {
        $job = new RenderRunSubClip($runId, $subClip);
        $serializedJobs[] = $job;
        $job->handle($objectStorage, $jobScratch, $compiler, $driver, $storageGuard);
        $firstBytes = Storage::disk()->get($subClip->outputKey()->value());
        $redeliveredJob = unserialize(serialize($job));
        $redeliveredJob->handle($objectStorage, $jobScratch, $compiler, $driver, $storageGuard);

        expect(Storage::disk()->get($subClip->outputKey()->value()))->toBe($firstBytes);
    }

    $finalizer = new FinalizeRunSplitOperation($runId, $subClips, $concat, $mux);
    $serializedJobs[] = $finalizer;
    $finalizer->handle(
        $objectStorage,
        $jobScratch,
        $compiler,
        $driver,
        $storageGuard,
    );

    foreach ($serializedJobs as $job) {
        expect(queuedJobAbsolutePathViolations($job))->toBe([])
            ->and(queuedJobKnownCompiledVocabularyViolations($job))->toBe([]);
    }

    $renderCommands = array_values(array_filter(
        $driver->commands,
        static fn (array $command): bool => ! in_array('-show_format', $command, true),
    ));
    $subClipCommands = array_values(array_filter(
        $renderCommands,
        static fn (array $command): bool => str_starts_with(basename($command[count($command) - 1]), 'subclip-'),
    ));
    $concatCommands = array_values(array_filter(
        $renderCommands,
        static fn (array $command): bool => basename($command[count($command) - 1]) === 'silent.mp4',
    ));
    $muxCommands = array_values(array_filter(
        $renderCommands,
        static fn (array $command): bool => basename($command[count($command) - 1]) === 'output.mp4',
    ));

    expect($plan->strategy)->toBe(ExecutorStrategy::SubOperationSplit)
        ->and($subClips)->toHaveCount(3)
        ->and(array_map(static fn (RenderSilentSubClip $operation): int => $operation->range->startMicroseconds, $subClips))
        ->toBe([0, 40_000_000, 80_000_000])
        ->and(array_map(static fn (RenderSilentSubClip $operation): int => $operation->range->endMicroseconds(), $subClips))
        ->toBe([40_000_000, 80_000_000, 120_000_000])
        ->and((new ReflectionObject($subClips[0]))->hasProperty('audioInput'))->toBeFalse()
        ->and($mux->audioInput->value())->toBe($recipe->audio->value())
        ->and($subClipCommands)->toHaveCount(6)
        ->and(array_all($subClipCommands, static fn (array $command): bool => count(array_keys($command, '-i', true)) === 1
            && ! in_array('-c:a', $command, true)))->toBeTrue()
        ->and($concat->mode)->toBe(VideoConcatMode::Copy)
        ->and($concatCommands)->toHaveCount(1)
        ->and($concatCommands[0])->toContain('-c', 'copy')
        ->and($muxCommands)->toHaveCount(1)
        ->and(count(array_keys($muxCommands[0], '-i', true)))->toBe(2)
        ->and($muxCommands[0])->toContain('-c:v', 'copy', '-c:a', 'aac')
        ->and(Storage::disk()->exists($mux->outputKey()->value()))->toBeTrue();

    (new CompleteRun($runId))->handle(resolve(RunCleanup::class));

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and(Storage::disk()->exists($mux->outputKey()->value()))->toBeTrue()
        ->and(Storage::disk()->exists($recipe->image->value()))->toBeFalse()
        ->and(Storage::disk()->exists($recipe->audio->value()))->toBeFalse()
        ->and(array_any($plan->intermediateObjectKeys(), static fn (ObjectKey $key): bool => Storage::disk()->exists($key->value())))
        ->toBeFalse();
});

test('distributes a budget plus one microsecond without a degenerate tail', function (): void {
    configureExecutorPolicy();
    $runId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $plan = resolve(RunExecutor::class)->plan(executorRun([
        executorRecipeOperation($runId, 0, 49_000_001),
    ], $runId));
    $ranges = array_values(array_map(
        static fn (RenderSilentSubClip $operation): int => $operation->range->durationMicroseconds,
        array_filter($plan->operations, static fn (object $operation): bool => $operation instanceof RenderSilentSubClip),
    ));

    expect($ranges)->toBe([24_500_001, 24_500_000]);
});

test('keeps operations at or below the output budget in one queued job', function (int $durationMicroseconds): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    $run = executorRun([executorRecipeOperation($runId, 0, $durationMicroseconds)], $runId);

    resolve(RunExecutor::class)->execute($run);

    Bus::assertChained([RenderRunOperation::class, CompleteRun::class]);
    expect($run->fresh()->status)->toBe(RunStatus::Running);
})->with([
    'below budget' => [48_000_000],
    'exactly at budget' => [49_000_000],
]);

test('does not dispatch a run whose running transition is a redelivery no op', function (): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = '17171717-1717-4717-8717-171717171717';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);
    resolve(RunExecutor::class)->execute($run);
    Bus::fake();

    resolve(RunExecutor::class)->execute($run->fresh());

    Bus::assertNothingDispatched();
    Bus::assertBatchCount(0);
});

test('executes the persisted recipe when in-memory raw state differs before transition', function (): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = '23232323-2323-4323-8323-232323232323';
    $foreignRunId = '24242424-2424-4424-8424-242424242424';
    $original = executorRecipeOperation($runId, 0, 30_000_000);
    $run = executorRun([$original], $runId);
    $attributes = $run->getAttributes();
    $attributes['recipe'] = (new RunRecipe([
        executorRecipeOperation($foreignRunId, 0, 30_000_000),
    ]))->toJson();
    $run->setRawAttributes($attributes);

    resolve(RunExecutor::class)->execute($run);

    Bus::assertChained([
        static fn (RenderRunOperation $job): bool => $job->operation->recipeOperation->image->value()
            === $original->image->value(),
        CompleteRun::class,
    ]);
});

test('failure cleanup does not delete outputs when the failure transition resolves to succeeded', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = '31313131-3131-4131-8131-313131313131';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);
    $run->transitionTo(RunStatus::Running);
    $output = RunObjectKeys::operationOutput($runId, 0);
    Storage::disk()->put($output->value(), 'completed output');
    Run::saving(static function (Run $saving): void {
        if ($saving->status === RunStatus::Failed) {
            $saving->status = RunStatus::Succeeded;
        }
    });

    resolve(RunFailure::class)->handle($runId);

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and(Storage::disk()->get($output->value()))->toBe('completed output');
});

test('completion preserves every output from the create-only recipe', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = '27272727-2727-4727-8727-272727272727';
    $run = executorRun([
        executorRecipeOperation($runId, 0, 30_000_000),
        executorRecipeOperation($runId, 1, 20_000_000),
    ], $runId);
    $run->transitionTo(RunStatus::Running);
    $prefix = RunObjectKeys::prefix($runId);
    $firstOutput = RunObjectKeys::operationOutput($runId, 0);
    $secondOutput = RunObjectKeys::operationOutput($runId, 1);
    Storage::disk()->put($firstOutput->value(), 'first');
    Storage::disk()->put($secondOutput->value(), 'second');
    Storage::disk()->put($prefix.'/work/intermediate.mp4', 'work');

    (new CompleteRun($runId))->handle(resolve(RunCleanup::class));

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and(Storage::disk()->get($firstOutput->value()))->toBe('first')
        ->and(Storage::disk()->get($secondOutput->value()))->toBe('second')
        ->and(Storage::disk()->exists($prefix.'/work/intermediate.mp4'))->toBeFalse();
});

test('marks a run failed when synchronous queue dispatch throws', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = '18181818-1818-4818-8818-181818181818';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);
    Bus::shouldReceive('chain')->once()->andThrow(new RuntimeException('queue unavailable'));

    expect(fn () => resolve(RunExecutor::class)->execute($run))
        ->toThrow(RuntimeException::class, 'queue unavailable');

    expect($run->fresh()->status)->toBe(RunStatus::Failed);
});

test('selects and dispatches fanout behind a persisted multi operation run', function (): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    $run = executorRun([
        executorRecipeOperation($runId, 0, 30_000_000),
        executorRecipeOperation($runId, 1, 20_000_000),
    ], $runId);

    resolve(RunExecutor::class)->execute($run);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2
        && $batch->jobs->every(static fn (object $job): bool => $job instanceof RenderRunOperation
            && $job->middleware()[0] instanceof SkipIfBatchUnavailable)
        && count($batch->finallyCallbacks()) === 1);
    expect(resolve(RunExecutor::class)->plan($run)->strategy)->toBe(ExecutorStrategy::Fanout)
        ->and((new ReflectionMethod(RunExecutor::class, 'selectStrategy'))->isPrivate())->toBeTrue();
});

test('defers failed fanout cleanup until the batch has settled', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    Bus::fake();
    $runId = '22222222-2222-4222-8222-222222222223';
    $run = executorRun([
        executorRecipeOperation($runId, 0, 30_000_000),
        executorRecipeOperation($runId, 1, 20_000_000),
    ], $runId);
    $prefix = RunObjectKeys::prefix($runId);
    $inFlightObject = ObjectKey::withinPrefix($prefix, $prefix.'/work/in-flight.mp4');
    Storage::disk()->put($inFlightObject->value(), 'in flight');

    resolve(RunExecutor::class)->execute($run);

    $batchedJob = null;
    $finally = null;
    Bus::assertBatched(function (PendingBatch $pendingBatch) use (&$batchedJob, &$finally): bool {
        $batchedJob = $pendingBatch->jobs->first();
        $finally = $pendingBatch->finallyCallbacks()[0];

        return true;
    });
    expect($batchedJob)->toBeInstanceOf(RenderRunOperation::class);
    $batchedJob->withBatchId('batch-id')->failed(new RuntimeException('render failed'));

    expect($run->fresh()->status)->toBe(RunStatus::Running)
        ->and(Storage::disk()->exists($inFlightObject->value()))->toBeTrue();

    $settledBatch = Mockery::mock(Batch::class);
    $settledBatch->shouldReceive('hasFailures')->once()->andReturnTrue();
    $finally($settledBatch);

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and(Storage::disk()->allFiles($prefix))->toBe([]);
});

test('moves a run to durable failure when completion dispatch fails in a successful batch callback', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    Bus::fake();
    $runId = '25252525-2525-4525-8525-252525252525';
    $run = executorRun([
        executorRecipeOperation($runId, 0, 30_000_000),
        executorRecipeOperation($runId, 1, 20_000_000),
    ], $runId);
    $prefix = RunObjectKeys::prefix($runId);
    Storage::disk()->put($prefix.'/work/pending-cleanup.mp4', 'partial');
    resolve(RunExecutor::class)->execute($run);
    $finally = null;
    Bus::assertBatched(function (PendingBatch $pendingBatch) use (&$finally): bool {
        $finally = $pendingBatch->finallyCallbacks()[0];

        return true;
    });
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('completion queue unavailable'));
    $settledBatch = Mockery::mock(Batch::class);
    $settledBatch->shouldReceive('hasFailures')->once()->andReturnFalse();

    $finally($settledBatch);

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and(Storage::disk()->allFiles($prefix))->toBe([]);
});

test('reports completion dispatch failure before containing failure cleanup errors', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', false);
    Bus::fake();
    $runId = '32323232-3232-4232-8232-323232323232';
    $run = executorRun([
        executorRecipeOperation($runId, 0, 30_000_000),
        executorRecipeOperation($runId, 1, 20_000_000),
    ], $runId);
    resolve(RunExecutor::class)->execute($run);
    $finally = null;
    Bus::assertBatched(function (PendingBatch $pendingBatch) use (&$finally): bool {
        $finally = $pendingBatch->finallyCallbacks()[0];

        return true;
    });
    $dispatchFailure = new RuntimeException('completion queue unavailable');
    Bus::shouldReceive('dispatch')->once()->andThrow($dispatchFailure);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with($dispatchFailure)->ordered();
    $handler->shouldReceive('report')->once()->with(Mockery::on(
        static fn (Throwable $exception): bool => str_contains($exception->getMessage(), 'requires an S3-backed'),
    ))->ordered();
    app()->instance(ExceptionHandler::class, $handler);
    $settledBatch = Mockery::mock(Batch::class);
    $settledBatch->shouldReceive('hasFailures')->once()->andReturnFalse();

    $finally($settledBatch);

    expect($run->fresh()->status)->toBe(RunStatus::Failed);
});

test('skips an SQS accepted batch member after partial bulk failure deletes its batch record', function (): void {
    configureExecutorPolicy();
    $runId = '26262626-2626-4626-8626-262626262626';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);
    $operation = resolve(RunExecutor::class)->plan($run)->operations[0];
    expect($operation)->toBeInstanceOf(WholeOperation::class);
    $job = (new RenderRunOperation($runId, $operation))->withBatchId('deleted-batch-id');
    $handled = false;

    (new SkipIfBatchUnavailable)->handle($job, function () use (&$handled): void {
        $handled = true;
    });

    expect($job->batch())->toBeNull()
        ->and($handled)->toBeFalse();
});

test('batch middleware runs jobs without a batch id and skips cancelled batches', function (): void {
    configureExecutorPolicy();
    $runId = '33333333-3333-4333-8333-333333333334';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);
    $operation = resolve(RunExecutor::class)->plan($run)->operations[0];
    expect($operation)->toBeInstanceOf(WholeOperation::class);
    $middleware = new SkipIfBatchUnavailable;
    $unbatchedHandled = false;
    $unbatched = new RenderRunOperation($runId, $operation);
    $middleware->handle($unbatched, function () use (&$unbatchedHandled): void {
        $unbatchedHandled = true;
    });
    [$cancelled] = (new RenderRunOperation($runId, $operation))->withFakeBatch(
        cancelledAt: CarbonImmutable::now(),
    );
    $cancelledHandled = false;
    $middleware->handle($cancelled, function () use (&$cancelledHandled): void {
        $cancelledHandled = true;
    });

    expect($unbatched->batchId)->toBeNull()
        ->and($unbatchedHandled)->toBeTrue()
        ->and($cancelledHandled)->toBeFalse();
});

test('dispatches executable sub clip jobs and finalization behind a split run', function (): void {
    configureExecutorPolicy();
    Bus::fake();
    $runId = '12121212-1212-4212-8212-121212121212';
    $run = executorRun([executorRecipeOperation($runId, 0, 120_000_000)], $runId);

    resolve(RunExecutor::class)->execute($run);

    Bus::assertChained([
        RenderRunSubClip::class,
        RenderRunSubClip::class,
        RenderRunSubClip::class,
        FinalizeRunSplitOperation::class,
        CompleteRun::class,
    ]);
});

test('rejects a non persisted run at the planning boundary', function (): void {
    configureExecutorPolicy();
    $runId = '13131313-1313-4313-8313-131313131313';
    $run = Run::factory()->make([
        'id' => $runId,
        'recipe' => (new RunRecipe([executorRecipeOperation($runId, 0, 30_000_000)]))->toJson(),
    ]);

    expect(fn () => resolve(RunExecutor::class)->plan($run))
        ->toThrow(InvalidArgumentException::class, 'persisted Run');
});

test('reports operational fallback use when cloud queue metadata remains unknown', function (): void {
    config()->set('queue.default', 'cloud_test');
    config()->set('queue.connections.cloud_test', [
        'driver' => 'cloud',
        'queues' => ['default' => ['unknown' => 300]],
    ]);
    Log::spy();
    $runId = '14141414-1414-4414-8414-141414141414';
    $run = executorRun([executorRecipeOperation($runId, 0, 30_000_000)], $runId);

    resolve(RunExecutor::class)->plan($run);

    Log::shouldHaveReceived('warning')->once()->with(
        'slate.execution.visibility_window_fallback',
        Mockery::on(static fn (array $context): bool => $context['connection'] === 'cloud_test'
            && $context['queue'] === 'default'
            && $context['visibility_window_seconds'] === 180),
    );
});

test('constructs identical sub clip bucket keys independently for the same persisted run', function (): void {
    configureExecutorPolicy();
    $runId = '15151515-1515-4515-8515-151515151515';
    $run = executorRun([executorRecipeOperation($runId, 0, 120_000_000)], $runId);
    $firstPlan = resolve(RunExecutor::class)->plan(Run::query()->findOrFail($run->id));
    $secondPlan = resolve(RunExecutor::class)->plan(Run::query()->findOrFail($run->id));
    $firstKeys = array_map(static fn (object $operation): string => $operation->outputKey()->value(), $firstPlan->operations);
    $secondKeys = array_map(static fn (object $operation): string => $operation->outputKey()->value(), $secondPlan->operations);

    expect($firstKeys)->toBe($secondKeys)
        ->and($firstKeys)->toContain(
            'slate-runs/15151515-1515-4515-8515-151515151515/work/operations/0000/subclips/0000.mp4',
            'slate-runs/15151515-1515-4515-8515-151515151515/work/operations/0000/subclips/0001.mp4',
            'slate-runs/15151515-1515-4515-8515-151515151515/work/operations/0000/subclips/0002.mp4',
        );
});

test('failed run cleanup removes nested sub clips inputs intermediates and outputs', function (): void {
    configureExecutorPolicy();
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = '16161616-1616-4616-8616-161616161616';
    $recipe = executorRecipeOperation($runId, 0, 120_000_000);
    $run = executorRun([$recipe], $runId);
    $prefix = RunObjectKeys::prefix($runId);
    $keys = [
        $recipe->image,
        $recipe->audio,
        RunObjectKeys::subClip($runId, 0, 0),
        RunObjectKeys::subClip($runId, 0, 1),
        RunObjectKeys::subClip($runId, 0, 2),
        RunObjectKeys::subClip($runId, 0, 9),
        RunObjectKeys::silentConcat($runId, 0),
        RunObjectKeys::operationOutput($runId, 0),
        ObjectKey::withinPrefix($prefix, $prefix.'/work/unexpected-orphan.bin'),
    ];

    foreach ($keys as $key) {
        Storage::disk()->put($key->value(), 'partial');
    }

    config()->set('queue.connections.executor_test.queues.render.retry_after', 300);
    resolve(RunFailure::class)->handle($runId);

    expect(Storage::disk()->allFiles($prefix))->toBe([])
        ->and($run->fresh()->status)->toBe(RunStatus::Failed);
});
