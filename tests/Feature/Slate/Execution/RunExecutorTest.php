<?php

use App\Models\Run;
use App\Slate\Execution\CopyConcatSubClips;
use App\Slate\Execution\ExecutorStrategy;
use App\Slate\Execution\MuxOperationAudio;
use App\Slate\Execution\RenderSilentSubClip;
use App\Slate\Execution\RunExecutor;
use App\Slate\Execution\WholeOperation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunRecipeOperation;

function executorRecipeOperation(int $durationMicroseconds): RunRecipeOperation
{
    return new RunRecipeOperation(
        OperationName::VideoStillClip,
        QualityTier::Delivery,
        $durationMicroseconds,
        true,
    );
}

/** @param non-empty-list<RunRecipeOperation> $operations */
function executorRun(array $operations): Run
{
    return Run::factory()->make([
        'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'recipe' => new RunRecipe($operations),
    ]);
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

test('splits an over budget operation into contiguous silent ranges then copy concats and muxes audio once', function (): void {
    configureExecutorPolicy();
    $plan = resolve(RunExecutor::class)->plan(executorRun([executorRecipeOperation(120_000_000)]));
    $subClips = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof RenderSilentSubClip,
    ));
    $concats = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof CopyConcatSubClips,
    ));
    $muxes = array_values(array_filter(
        $plan->operations,
        static fn (object $operation): bool => $operation instanceof MuxOperationAudio,
    ));

    expect($plan->strategy)->toBe(ExecutorStrategy::SubOperationSplit)
        ->and($subClips)->toHaveCount(3)
        ->and(array_map(static fn (RenderSilentSubClip $operation): int => $operation->range->startMicroseconds, $subClips))
        ->toBe([0, 49_000_000, 98_000_000])
        ->and(array_map(static fn (RenderSilentSubClip $operation): int => $operation->range->durationMicroseconds, $subClips))
        ->toBe([49_000_000, 49_000_000, 22_000_000])
        ->and(array_map(static fn (RenderSilentSubClip $operation): int => $operation->range->endMicroseconds(), $subClips))
        ->toBe([49_000_000, 98_000_000, 120_000_000])
        ->and(array_filter($subClips, static fn (RenderSilentSubClip $operation): bool => $operation->includesAudio()))
        ->toBe([])
        ->and($concats)->toHaveCount(1)
        ->and($concats[0]->mode)->toBe(VideoConcatMode::Copy)
        ->and($muxes)->toHaveCount(1);
});

test('keeps operations at or below the output budget in one job', function (int $durationMicroseconds): void {
    configureExecutorPolicy();

    $plan = resolve(RunExecutor::class)->plan(executorRun([executorRecipeOperation($durationMicroseconds)]));

    expect($plan->strategy)->toBe(ExecutorStrategy::SingleJob)
        ->and($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0])->toBeInstanceOf(WholeOperation::class);
})->with([
    'below budget' => [48_000_000],
    'exactly at budget' => [49_000_000],
]);

test('selects fanout behind the run for a multi operation recipe', function (): void {
    configureExecutorPolicy();

    $plan = resolve(RunExecutor::class)->plan(executorRun([
        executorRecipeOperation(30_000_000),
        executorRecipeOperation(20_000_000),
    ]));

    expect($plan->strategy)->toBe(ExecutorStrategy::Fanout)
        ->and($plan->operations)->toHaveCount(2)
        ->and((new ReflectionMethod(RunExecutor::class, 'selectStrategy'))->isPrivate())->toBeTrue();
});

test('constructs identical sub clip bucket keys independently for the same run and indexes', function (): void {
    configureExecutorPolicy();
    $firstPlan = resolve(RunExecutor::class)->plan(executorRun([executorRecipeOperation(120_000_000)]));
    $secondPlan = resolve(RunExecutor::class)->plan(executorRun([executorRecipeOperation(120_000_000)]));
    $firstKeys = array_map(static fn (object $operation): string => $operation->outputKey()->value(), $firstPlan->operations);
    $secondKeys = array_map(static fn (object $operation): string => $operation->outputKey()->value(), $secondPlan->operations);

    expect($firstKeys)->toBe($secondKeys)
        ->and($firstKeys)->toContain(
            'slate-runs/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb/work/operations/0000/subclips/0000.mp4',
            'slate-runs/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb/work/operations/0000/subclips/0001.mp4',
            'slate-runs/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb/work/operations/0000/subclips/0002.mp4',
        );
});
