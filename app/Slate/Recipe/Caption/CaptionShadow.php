<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Caption;

use InvalidArgumentException;

final readonly class CaptionShadow
{
    public function __construct(
        public CaptionShadowColor $color,
        public int $xPixels,
        public int $yPixels,
    ) {
        if (abs($xPixels) > 32 || abs($yPixels) > 32) {
            throw new InvalidArgumentException('Caption shadow offsets must remain within 32 pixels of the text.');
        }
    }
}
