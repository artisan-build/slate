<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Caption;

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
        if (trim($text) === '' || preg_match('/[\x00-\x1F\x7F]/u', $text) !== 0) {
            throw new InvalidArgumentException('Timed word text must be non-empty and contain no control characters.');
        }

        if ($xPixels < 0 || $yPixels < 0) {
            throw new InvalidArgumentException('Timed word pixel coordinates must be non-negative.');
        }

        $timelineValues = [
            $enterSeconds,
            $enterDurationSeconds,
            $spokenEndSeconds,
            $clearSeconds,
            $exitDurationSeconds,
        ];

        if (array_any($timelineValues, static fn (float $value): bool => ! is_finite($value) || $value < 0)) {
            throw new InvalidArgumentException('Timed word timeline values must be finite and non-negative.');
        }

        if ($enterDurationSeconds === 0.0 || $exitDurationSeconds === 0.0) {
            throw new InvalidArgumentException('Timed word transition durations must be greater than zero.');
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
