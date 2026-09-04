<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Models\Run;
use App\Slate\Run\RunRecipeOperation;
use App\Slate\Storage\ObjectKey;

final readonly class RunExecutor
{
    public function __construct(private QueueRuntimePolicy $runtimePolicy) {}

    public function plan(Run $run): ExecutionPlan
    {
        $settings = $this->runtimePolicy->forQueue((string) config('slate.execution.render_queue'));
        $strategy = $this->selectStrategy($run, $settings);
        $operations = [];

        foreach ($run->recipe->operations as $operationIndex => $recipeOperation) {
            $budgetMicroseconds = $settings->outputSecondsPerJob($recipeOperation->qualityTier) * 1_000_000;

            if ($recipeOperation->durationMicroseconds <= $budgetMicroseconds) {
                $operations[] = new WholeOperation(
                    $operationIndex,
                    $recipeOperation,
                    $this->key($run, $operationIndex, 'output.mp4'),
                );

                continue;
            }

            array_push(
                $operations,
                ...$this->splitOperation($run, $operationIndex, $recipeOperation, $budgetMicroseconds),
            );
        }

        return new ExecutionPlan($strategy, $operations, $settings);
    }

    private function selectStrategy(Run $run, QueueRuntimeSettings $settings): ExecutorStrategy
    {
        foreach ($run->recipe->operations as $operation) {
            if ($operation->durationMicroseconds
                > $settings->outputSecondsPerJob($operation->qualityTier) * 1_000_000) {
                return ExecutorStrategy::SubOperationSplit;
            }
        }

        return count($run->recipe->operations) === 1
            ? ExecutorStrategy::SingleJob
            : ExecutorStrategy::Fanout;
    }

    /** @return non-empty-list<PlannedOperation> */
    private function splitOperation(
        Run $run,
        int $operationIndex,
        RunRecipeOperation $recipeOperation,
        int $budgetMicroseconds,
    ): array {
        $planned = [];
        $subClipKeys = [];
        $startMicroseconds = 0;
        $subIndex = 0;

        while ($startMicroseconds < $recipeOperation->durationMicroseconds) {
            $durationMicroseconds = min(
                $budgetMicroseconds,
                $recipeOperation->durationMicroseconds - $startMicroseconds,
            );
            $subClipKey = $this->key(
                $run,
                $operationIndex,
                sprintf('subclips/%04d.mp4', $subIndex),
            );
            $planned[] = new RenderSilentSubClip(
                $operationIndex,
                $subIndex,
                $recipeOperation,
                new TimeRange($startMicroseconds, $durationMicroseconds),
                $subClipKey,
            );
            $subClipKeys[] = $subClipKey;
            $startMicroseconds += $durationMicroseconds;
            $subIndex++;
        }

        $concatOutput = $this->key(
            $run,
            $operationIndex,
            $recipeOperation->hasAudio ? 'silent.mp4' : 'output.mp4',
        );
        $planned[] = new CopyConcatSubClips($operationIndex, $subClipKeys, $concatOutput);

        if ($recipeOperation->hasAudio) {
            $planned[] = new MuxOperationAudio(
                $operationIndex,
                $concatOutput,
                $this->key($run, $operationIndex, 'output.mp4'),
            );
        }

        return $planned;
    }

    private function key(Run $run, int $operationIndex, string $suffix): ObjectKey
    {
        $prefix = 'slate-runs/'.$run->id;

        return ObjectKey::withinPrefix(
            $prefix,
            sprintf('%s/work/operations/%04d/%s', $prefix, $operationIndex, $suffix),
        );
    }
}
