<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use InvalidArgumentException;

final readonly class VideoFadeOut
{
    public function __construct(
        public float $startSeconds,
        public float $durationSeconds,
        public VideoColor $color,
    ) {
        if (! AudioDuration::isNonNegativeMicrosecondDuration($startSeconds)) {
            throw new InvalidArgumentException('Video fade start must be a non-negative microsecond-precision duration.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Video fade duration must be a positive microsecond-precision duration.');
        }
    }
}
