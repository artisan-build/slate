<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use InvalidArgumentException;

final readonly class KenBurnsMotion
{
    public function __construct(
        public float $zoomPercent,
        public KenBurnsDirection $direction,
        public KenBurnsEase $ease,
    ) {
        if (! is_finite($zoomPercent) || $zoomPercent <= 0.0 || $zoomPercent > 6.0) {
            throw new InvalidArgumentException('Ken Burns zoom must be greater than zero and no more than the audited 6 percent maximum.');
        }
    }
}
