<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use InvalidArgumentException;

final readonly class AudioConcatSilence
{
    public function __construct(public float $durationSeconds)
    {
        if (! is_finite($durationSeconds) || $durationSeconds <= 0 || $durationSeconds > 1.5) {
            throw new InvalidArgumentException('An inline silence gap must be between 0 and 1.5 seconds.');
        }
    }
}
