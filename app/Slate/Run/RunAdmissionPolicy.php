<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Recipe\QualityTier;
use InvalidArgumentException;

final readonly class RunAdmissionPolicy
{
    public function __construct(private QueueRuntimePolicy $runtimePolicy) {}

    public function rejectionReason(RunRecipe $recipe): ?InputRejectionReason
    {
        if (count($recipe->operations) > $this->maximumOperations()) {
            return InputRejectionReason::OperationCountExceeded;
        }

        $totalDurationMicroseconds = array_sum(array_map(
            static fn (RunRecipeOperation $operation): int => $operation->durationMicroseconds(),
            $recipe->operations,
        ));

        if ($totalDurationMicroseconds > $this->maximumTotalOutputSeconds() * 1_000_000) {
            return InputRejectionReason::TotalOutputDurationExceeded;
        }

        if ($this->estimatedQueueJobs($recipe) > $this->maximumQueueJobs()) {
            return InputRejectionReason::RenderJobCountExceeded;
        }

        return null;
    }

    public function maximumQueueJobs(): int
    {
        return $this->positiveConfig('slate.api.worker_ceiling')
            * $this->positiveConfig('slate.api.maximum_queue_waves_per_run');
    }

    public function maximumOperations(): int
    {
        // A source clip can require one coarse-cut and one precise-render job; reserve one job for completion.
        return intdiv($this->maximumQueueJobs() - 1, 2);
    }

    public function maximumTotalOutputSeconds(): int
    {
        $settings = $this->runtimePolicy->forQueue((string) config('slate.execution.render_queue'));
        $smallestOutputBudget = min(array_map(
            static fn (QualityTier $tier): int => $settings->outputSecondsPerJob($tier),
            QualityTier::cases(),
        ));

        return $this->maximumQueueJobs() * $smallestOutputBudget;
    }

    public function message(InputRejectionReason $reason): string
    {
        return match ($reason) {
            InputRejectionReason::OperationCountExceeded => sprintf(
                'A run may contain at most %d operations.',
                $this->maximumOperations(),
            ),
            InputRejectionReason::TotalOutputDurationExceeded => sprintf(
                'A run may contain at most %d seconds of total output.',
                $this->maximumTotalOutputSeconds(),
            ),
            InputRejectionReason::RenderJobCountExceeded => sprintf(
                'A run may require at most %d queued execution jobs.',
                $this->maximumQueueJobs(),
            ),
            default => throw new InvalidArgumentException('The rejection reason is not produced by run admission.'),
        };
    }

    private function estimatedQueueJobs(RunRecipe $recipe): int
    {
        $settings = $this->runtimePolicy->forQueue((string) config('slate.execution.render_queue'));
        $containsVideoClips = array_any(
            $recipe->operations,
            static fn (RunRecipeOperation $operation): bool => $operation instanceof RunVideoClip,
        );

        if ($containsVideoClips) {
            return count($recipe->operations) * 2 + 1;
        }

        $jobs = 1;

        foreach ($recipe->operations as $operation) {
            $budgetMicroseconds = $settings->outputSecondsPerJob($operation->qualityTier()) * 1_000_000;
            $renderJobs = intdiv($operation->durationMicroseconds() + $budgetMicroseconds - 1, $budgetMicroseconds);
            $jobs += $renderJobs + ($renderJobs > 1 ? 1 : 0);
        }

        return $jobs;
    }

    private function positiveConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Configuration {$key} must be a positive integer.");
        }

        return $value;
    }
}
