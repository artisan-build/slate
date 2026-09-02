<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Caption;

use App\Slate\Recipe\Audio\AudioDuration;
use InvalidArgumentException;

final readonly class CaptionDim
{
    public function __construct(
        public float $alpha,
        public float $durationSeconds,
    ) {
        if (! is_finite($alpha) || $alpha <= 0.0 || $alpha >= 1.0) {
            throw new InvalidArgumentException('Caption dim alpha must be finite and between zero and one.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Caption dim duration must be a positive microsecond-precision duration.');
        }
    }
}
