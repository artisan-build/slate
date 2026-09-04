<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Recipe\QualityTier;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class QueueRuntimePolicy
{
    public function __construct(private Repository $config) {}

    /**
     * Resolution order is deliberately narrow and observable: an explicit retry_after on the named
     * queue, then the connection's Laravel retry_after, then Slate's conservative fallback. Laravel
     * Cloud's injected `queues` map is not assigned an unobserved attribute shape here; until that
     * shape is confirmed, it reaches the explicit ConfiguredFallback result instead of a silent guess.
     */
    public function forQueue(string $queue, ?string $connection = null): QueueRuntimeSettings
    {
        $connection ??= $this->stringConfig('queue.default');

        if ($queue === '' || $connection === '') {
            throw new InvalidArgumentException('A queue connection and named queue are required.');
        }

        [$visibilityWindowSeconds, $source] = $this->visibilityWindow($connection, $queue);
        $jobTimeoutSeconds = $visibilityWindowSeconds - $this->positiveIntConfig(
            'slate.execution.job_timeout_margin_seconds',
        );
        $driverTimeoutSeconds = $jobTimeoutSeconds - $this->positiveIntConfig(
            'slate.execution.driver_timeout_margin_seconds',
        );

        if ($driverTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Configured queue margins leave no positive driver timeout.');
        }

        $budgetMargin = $this->config->get('slate.execution.output_budget_margin');

        if (! is_float($budgetMargin) && ! is_int($budgetMargin)) {
            throw new InvalidArgumentException('Output budget margin must be numeric.');
        }

        $budgetMargin = (float) $budgetMargin;

        if ($budgetMargin <= 0 || $budgetMargin > 1) {
            throw new InvalidArgumentException('Output budget margin must be greater than zero and at most one.');
        }

        $outputSecondsByTier = [];

        foreach (QualityTier::cases() as $qualityTier) {
            $rate = $this->config->get('slate.execution.measured_realtime_rates.'.$qualityTier->value);

            if ((! is_float($rate) && ! is_int($rate)) || $rate <= 0) {
                throw new InvalidArgumentException('Every quality tier requires a positive measured realtime rate.');
            }

            $outputSecondsByTier[$qualityTier->value] = max(
                1,
                (int) floor($driverTimeoutSeconds * (float) $rate * $budgetMargin),
            );
        }

        return new QueueRuntimeSettings(
            $connection,
            $queue,
            $visibilityWindowSeconds,
            $source,
            $jobTimeoutSeconds,
            $driverTimeoutSeconds,
            $outputSecondsByTier,
        );
    }

    /** @return array{int, VisibilityWindowSource} */
    private function visibilityWindow(string $connection, string $queue): array
    {
        $namedQueueWindow = $this->positiveInt($this->config->get(
            "queue.connections.{$connection}.queues.{$queue}.retry_after",
        ));

        if ($namedQueueWindow !== null) {
            return [$namedQueueWindow, VisibilityWindowSource::NamedQueueRetryAfter];
        }

        $connectionWindow = $this->positiveInt($this->config->get(
            "queue.connections.{$connection}.retry_after",
        ));

        if ($connectionWindow !== null) {
            return [$connectionWindow, VisibilityWindowSource::ConnectionRetryAfter];
        }

        return [
            $this->positiveIntConfig('slate.execution.visibility_window_fallback_seconds'),
            VisibilityWindowSource::ConfiguredFallback,
        ];
    }

    private function positiveIntConfig(string $key): int
    {
        $value = $this->positiveInt($this->config->get($key));

        if ($value === null) {
            throw new InvalidArgumentException("Configuration {$key} must be a positive integer.");
        }

        return $value;
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key);

        if (! is_string($value)) {
            throw new InvalidArgumentException("Configuration {$key} must be a string.");
        }

        return $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
