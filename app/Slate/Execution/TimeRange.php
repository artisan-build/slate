<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use InvalidArgumentException;

final readonly class TimeRange
{
    public function __construct(
        public int $startMicroseconds,
        public int $durationMicroseconds,
    ) {
        if ($startMicroseconds < 0 || $durationMicroseconds < 1) {
            throw new InvalidArgumentException('A time range requires a non-negative start and positive duration.');
        }
    }

    public function endMicroseconds(): int
    {
        return $this->startMicroseconds + $this->durationMicroseconds;
    }
}
