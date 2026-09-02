<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Caption;

use App\Slate\Recipe\Audio\AudioDuration;
use InvalidArgumentException;

final readonly class TimedWord
{
    private function __construct(
        public string $text,
        public int $xPixels,
        public int $yPixels,
        public float $enterSeconds,
        public float $enterDurationSeconds,
        public float $spokenEndSeconds,
        public float $clearSeconds,
        public float $exitDurationSeconds,
    ) {}

    public static function fromValues(
        string $text,
        int $xPixels,
        int $yPixels,
        float $enterSeconds,
        float $enterDurationSeconds,
        float $spokenEndSeconds,
        float $clearSeconds,
        float $exitDurationSeconds,
    ): self {
        if (trim($text) === '' || preg_match('/[\x00-\x1F\x7F]/u', $text) !== 0 || mb_strlen($text) > 128) {
            throw new InvalidArgumentException('Timed word text must contain 1 to 128 characters and no control characters.');
        }

        if ($xPixels < 0 || $xPixels > 1535 || $yPixels < 0 || $yPixels > 1535) {
            throw new InvalidArgumentException('Timed word pixel coordinates must be within the audited canvas bounds.');
        }

        $nonNegativeTimelineValues = [
            $enterSeconds,
            $spokenEndSeconds,
            $clearSeconds,
        ];

        if (array_any(
            $nonNegativeTimelineValues,
            static fn (float $value): bool => ! AudioDuration::isNonNegativeMicrosecondDuration($value),
        )) {
            throw new InvalidArgumentException('Timed word timeline values must be non-negative microsecond-precision durations.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($enterDurationSeconds)
            || ! AudioDuration::isPositiveMicrosecondDuration($exitDurationSeconds)) {
            throw new InvalidArgumentException('Timed word transition durations must be positive microsecond-precision durations.');
        }

        if ($enterSeconds + $enterDurationSeconds > $spokenEndSeconds || $spokenEndSeconds > $clearSeconds) {
            throw new InvalidArgumentException('Timed word timeline values must be chronological.');
        }

        return new self(
            $text,
            $xPixels,
            $yPixels,
            $enterSeconds,
            $enterDurationSeconds,
            $spokenEndSeconds,
            $clearSeconds,
            $exitDurationSeconds,
        );
    }
}
