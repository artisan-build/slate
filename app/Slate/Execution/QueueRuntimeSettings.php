<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Recipe\QualityTier;
use InvalidArgumentException;

final readonly class QueueRuntimeSettings
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $visibilityWindowSeconds,
        public VisibilityWindowSource $visibilityWindowSource,
        public int $jobTimeoutSeconds,
        public int $driverTimeoutSeconds,
        /** @var array<string, int> */
        private array $outputSecondsByTier,
    ) {
        if ($connection === '' || $queue === '') {
            throw new InvalidArgumentException('Queue runtime settings require a connection and named queue.');
        }

        if (! ($driverTimeoutSeconds < $jobTimeoutSeconds
            && $jobTimeoutSeconds < $visibilityWindowSeconds)) {
            throw new InvalidArgumentException('Queue runtime settings must bind driver timeout before job timeout before visibility expiry.');
        }

        foreach (QualityTier::cases() as $qualityTier) {
            if (($outputSecondsByTier[$qualityTier->value] ?? 0) < 1) {
                throw new InvalidArgumentException('Every quality tier requires a positive output budget.');
            }
        }
    }

    public function usedFallback(): bool
    {
        return $this->visibilityWindowSource === VisibilityWindowSource::ConfiguredFallback;
    }

    public function outputSecondsPerJob(QualityTier $qualityTier): int
    {
        return $this->outputSecondsByTier[$qualityTier->value];
    }
}
