<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use InvalidArgumentException;

final readonly class OverlayFadeIn
{
    public function __construct(
        public float $startSeconds,
        public float $durationSeconds,
    ) {
        if (! AudioDuration::isNonNegativeMicrosecondDuration($startSeconds)) {
            throw new InvalidArgumentException('Overlay fade start must be a non-negative microsecond-precision duration.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Overlay fade duration must be a positive microsecond-precision duration.');
        }
    }
}
