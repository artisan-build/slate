<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Jobs\AssembleRunDelivery;
use App\Jobs\CoarseCutVideoSource;
use App\Jobs\FinalizeRunSplitOperation;
use App\Jobs\RenderRunOperation;
use App\Jobs\RenderRunSubClip;
use App\Jobs\RenderVideoClip;
use App\Models\Run;
use App\Models\RunStatus;
use App\Services\RunFailure;
use App\Slate\Input\InputRejectedException;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunRecipeOperation;
use App\Slate\Run\RunStillClip;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class RunExecutor
{
    public function __construct(private QueueRuntimePolicy $runtimePolicy) {}

    public function execute(Run $run): void
    {
        if (! $run->transitionTo(RunStatus::Running)) {
            return;
        }

        try {
            $run = Run::query()->findOrFail($run->id);
            $plan = $this->plan($run);

            match ($plan->strategy) {
                ExecutorStrategy::SingleJob => $this->dispatchSingle($run, $plan),
                ExecutorStrategy::Fanout => $this->dispatchFanout($run, $plan),
                ExecutorStrategy::SubOperationSplit => $this->dispatchSplit($run, $plan),
                ExecutorStrategy::VideoClipFanout => $this->dispatchVideoClips($run, $plan),
            };
        } catch (Throwable $exception) {
            resolve(RunFailure::class)->handle($run->id);

            throw $exception;
        }
    }

    public function plan(Run $run): ExecutionPlan
    {
        $runId = $this->persistedRunId($run);
        $settings = $this->runtimePolicy->forQueue((string) config('slate.execution.render_queue'));
        $recipe = $run->recipe();

        if ($settings->usedFallback()) {
            Log::warning('slate.execution.visibility_window_fallback', [
                'connection' => $settings->connection,
                'queue' => $settings->queue,
                'visibility_window_seconds' => $settings->visibilityWindowSeconds,
            ]);
        }

        $videoClipCount = count(array_filter(
            $recipe->operations,
            static fn (RunRecipeOperation $operation): bool => $operation instanceof RunVideoClip,
        ));

        if ($videoClipCount > 0) {
            if ($videoClipCount !== count($recipe->operations)) {
                throw new InvalidArgumentException('Video clip runs cannot mix source clips with assembler operations.');
            }

            return $this->planVideoClips($runId, $recipe, $settings);
        }

        $strategy = $this->selectStrategy($recipe, $settings);
        $operations = [];

        foreach ($recipe->operations as $operationIndex => $recipeOperation) {
            if (! $recipeOperation instanceof RunStillClip) {
                throw new InvalidArgumentException('Run executor received an unsupported persisted operation.');
            }

            $budgetMicroseconds = $settings->outputSecondsPerJob($recipeOperation->qualityTier()) * 1_000_000;

            if ($recipeOperation->durationMicroseconds() <= $budgetMicroseconds) {
                $operations[] = new WholeOperation(
                    $operationIndex,
                    $recipeOperation,
                    RunObjectKeys::operationOutput($runId, $operationIndex),
                );

                continue;
            }

            array_push(
                $operations,
                ...$this->splitOperation($runId, $operationIndex, $recipeOperation, $budgetMicroseconds),
            );
        }

        return new ExecutionPlan($strategy, $operations, $settings);
    }

    private function selectStrategy(RunRecipe $recipe, QueueRuntimeSettings $settings): ExecutorStrategy
    {
        foreach ($recipe->operations as $operation) {
            if ($operation->durationMicroseconds()
                > $settings->outputSecondsPerJob($operation->qualityTier()) * 1_000_000) {
                return ExecutorStrategy::SubOperationSplit;
            }
        }

        return count($recipe->operations) === 1
            ? ExecutorStrategy::SingleJob
            : ExecutorStrategy::Fanout;
    }

    /** @return non-empty-list<PlannedOperation> */
    private function splitOperation(
        string $runId,
        int $operationIndex,
        RunStillClip $recipeOperation,
        int $budgetMicroseconds,
    ): array {
        $subClipCount = (int) ceil($recipeOperation->durationMicroseconds() / $budgetMicroseconds);
        $baseDuration = intdiv($recipeOperation->durationMicroseconds(), $subClipCount);
        $extraMicroseconds = $recipeOperation->durationMicroseconds() % $subClipCount;
        $planned = [];
        $subClipKeys = [];
        $startMicroseconds = 0;

        for ($subIndex = 0; $subIndex < $subClipCount; $subIndex++) {
            $durationMicroseconds = $baseDuration + ($subIndex < $extraMicroseconds ? 1 : 0);
            $subClipKey = RunObjectKeys::subClip($runId, $operationIndex, $subIndex);
            $planned[] = new RenderSilentSubClip(
                $operationIndex,
                $subIndex,
                $recipeOperation->image,
                new TimeRange($startMicroseconds, $durationMicroseconds),
                $recipeOperation->width,
                $recipeOperation->height,
                $recipeOperation->framesPerSecond,
                $recipeOperation->fit,
                $recipeOperation->padColor,
                $recipeOperation->supersample,
                $recipeOperation->qualityTier,
                $subClipKey,
            );
            $subClipKeys[] = $subClipKey;
            $startMicroseconds += $durationMicroseconds;
        }

        $concatOutput = RunObjectKeys::silentConcat($runId, $operationIndex);
        $planned[] = new CopyConcatSubClips($operationIndex, $subClipKeys, $concatOutput);
        $planned[] = new MuxOperationAudio(
            $operationIndex,
            $concatOutput,
            $recipeOperation->audioSource(),
            $recipeOperation->audioBitrate(),
            RunObjectKeys::operationOutput($runId, $operationIndex),
        );

        return $planned;
    }

    private function dispatchSingle(Run $run, ExecutionPlan $plan): void
    {
        $operation = $plan->operations[0];

        if (! $operation instanceof WholeOperation) {
            throw new InvalidArgumentException('Single-job strategy requires one whole operation.');
        }

        Bus::chain([
            new RenderRunOperation($run->id, $operation),
            new AssembleRunDelivery($run->id),
        ])->onQueue($plan->runtimeSettings->queue)->dispatch();
    }

    private function dispatchFanout(Run $run, ExecutionPlan $plan): void
    {
        $jobs = array_map(
            static function (PlannedOperation $operation) use ($run): RenderRunOperation {
                if (! $operation instanceof WholeOperation) {
                    throw new InvalidArgumentException('Fan-out strategy requires whole operations.');
                }

                return new RenderRunOperation($run->id, $operation);
            },
            $plan->operations,
        );
        $runId = $run->id;

        Bus::batch($jobs)
            ->name('Slate run '.$runId)
            ->finally(static function (Batch $batch) use ($runId): void {
                if ($batch->hasFailures()) {
                    resolve(RunFailure::class)->handle($runId);

                    return;
                }

                try {
                    Bus::dispatch(new AssembleRunDelivery($runId));
                } catch (Throwable $exception) {
                    report($exception);

                    try {
                        resolve(RunFailure::class)->handle($runId);
                    } catch (Throwable $failureException) {
                        report($failureException);
                    }
                }
            })
            ->onQueue($plan->runtimeSettings->queue)
            ->dispatch();
    }

    private function dispatchSplit(Run $run, ExecutionPlan $plan): void
    {
        $jobs = [];
        $subClips = [];
        $concat = null;

        foreach ($plan->operations as $operation) {
            if ($operation instanceof WholeOperation) {
                $jobs[] = new RenderRunOperation($run->id, $operation);
            } elseif ($operation instanceof RenderSilentSubClip) {
                $subClips[] = $operation;
                $jobs[] = new RenderRunSubClip($run->id, $operation);
            } elseif ($operation instanceof CopyConcatSubClips) {
                $concat = $operation;
            } elseif ($operation instanceof MuxOperationAudio) {
                if ($concat === null || $subClips === []) {
                    throw new InvalidArgumentException('Split finalization requires its typed sub-clips and concat.');
                }

                $jobs[] = new FinalizeRunSplitOperation($run->id, $subClips, $concat, $operation);
                $subClips = [];
                $concat = null;
            }
        }

        $jobs[] = new AssembleRunDelivery($run->id);

        Bus::chain($jobs)->onQueue($plan->runtimeSettings->queue)->dispatch();
    }

    private function planVideoClips(
        string $runId,
        RunRecipe $recipe,
        QueueRuntimeSettings $settings,
    ): ExecutionPlan {
        $rangesBySource = [];
        $sources = [];
        $preciseOperations = [];
        $paddingMicroseconds = 5 * 1_000_000;

        foreach ($recipe->operations as $operationIndex => $operation) {
            if (! $operation instanceof RunVideoClip) {
                throw new InvalidArgumentException('Video clip planning requires only typed source clips.');
            }

            $source = $operation->source->value();
            $coarseOutput = RunObjectKeys::coarseClip($runId, $operationIndex);
            $startMicroseconds = $operation->startMicroseconds();
            $endMicroseconds = $operation->endMicroseconds();
            $coarseStart = max(0, $startMicroseconds - $paddingMicroseconds);
            $sources[$source] = $operation->source;
            $rangesBySource[$source][] = new CoarseCutRange(
                $operationIndex,
                $startMicroseconds,
                $endMicroseconds,
                $coarseOutput,
            );
            $preciseOperations[] = new PreciseVideoClip(
                $operationIndex,
                $coarseOutput,
                $startMicroseconds - $coarseStart,
                $operation->durationMicroseconds(),
                $operation->qualityTier(),
                RunObjectKeys::operationOutput($runId, $operationIndex),
            );
        }

        $coarseOperations = [];

        foreach ($rangesBySource as $source => $ranges) {
            $coarseOperations[] = new CoarseCutSource($sources[$source], $ranges);
        }

        return new ExecutionPlan(
            ExecutorStrategy::VideoClipFanout,
            [...$coarseOperations, ...$preciseOperations],
            $settings,
        );
    }

    private function dispatchVideoClips(Run $run, ExecutionPlan $plan): void
    {
        $coarseJobs = [];
        $preciseOperations = [];

        foreach ($plan->operations as $operation) {
            if ($operation instanceof CoarseCutSource) {
                $coarseJobs[] = new CoarseCutVideoSource($run->id, $operation);
            } elseif ($operation instanceof PreciseVideoClip) {
                $preciseOperations[] = $operation;
            } else {
                throw new InvalidArgumentException('Video clip fan-out received an unrelated planned operation.');
            }
        }

        $runId = $run->id;
        $queue = $plan->runtimeSettings->queue;

        Bus::batch($coarseJobs)
            ->name('Slate coarse cuts '.$runId)
            ->then(static function (Batch $batch) use ($runId, $preciseOperations, $queue): void {
                $renderJobs = array_map(
                    static fn (PreciseVideoClip $operation): RenderVideoClip => new RenderVideoClip($runId, $operation),
                    $preciseOperations,
                );

                Bus::batch($renderJobs)
                    ->name('Slate precise clips '.$runId)
                    ->finally(static function (Batch $renderBatch) use ($runId): void {
                        if ($renderBatch->hasFailures()) {
                            resolve(RunFailure::class)->handle($runId);

                            return;
                        }

                        Bus::dispatch(new AssembleRunDelivery($runId));
                    })
                    ->onQueue($queue)
                    ->dispatch();
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($runId): void {
                resolve(RunFailure::class)->handle(
                    $runId,
                    $exception instanceof InputRejectedException ? $exception->reason : null,
                );
            })
            ->onQueue($queue)
            ->dispatch();
    }

    private function persistedRunId(Run $run): string
    {
        $runId = $run->getKey();

        if (! $run->exists || ! is_string($runId)) {
            throw new InvalidArgumentException('Run execution requires a persisted Run.');
        }

        return $runId;
    }
}
