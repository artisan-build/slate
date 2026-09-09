<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class PreciseVideoClip implements PlannedOperation
{
    public function __construct(
        public int $index,
        public ObjectKey $coarseInput,
        public int $startMicroseconds,
        public int $durationMicroseconds,
        public QualityTier $qualityTier,
        private ObjectKey $output,
    ) {
        if ($index < 0 || $startMicroseconds < 0 || $durationMicroseconds < 1) {
            throw new InvalidArgumentException('A precise video clip requires a valid index and time range.');
        }
    }

    public function operationIndex(): int
    {
        return $this->index;
    }

    public function outputKey(): ObjectKey
    {
        return $this->output;
    }
}
