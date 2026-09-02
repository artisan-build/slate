<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use InvalidArgumentException;

final readonly class AudioConcatSilence
{
    public function __construct(public float $durationSeconds)
    {
        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds) || $durationSeconds > 1.5) {
            throw new InvalidArgumentException('An inline silence gap must be between one microsecond and 1.5 seconds at microsecond precision.');
        }
    }
}
