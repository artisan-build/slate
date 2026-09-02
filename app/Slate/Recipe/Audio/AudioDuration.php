<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

/**
 * Keeps compiler arithmetic exact while allowing substantially more than the v1 nine-minute workload.
 */
final class AudioDuration
{
    public const float MaxSeconds = 86400.0;

    private const float Microsecond = 0.000001;

    private const float Millisecond = 0.001;

    public static function isPositiveMicrosecondDuration(float $seconds): bool
    {
        return $seconds >= self::Microsecond
            && self::isMicrosecondDuration($seconds);
    }

    public static function isNonNegativeMicrosecondDuration(float $seconds): bool
    {
        return ($seconds === 0.0 || $seconds >= self::Microsecond)
            && self::isMicrosecondDuration($seconds);
    }

    public static function isNonNegativeMillisecondDuration(float $seconds): bool
    {
        return ($seconds === 0.0 || $seconds >= self::Millisecond)
            && self::isRepresentable($seconds, 3);
    }

    private static function isMicrosecondDuration(float $seconds): bool
    {
        return self::isRepresentable($seconds, 6);
    }

    private static function isRepresentable(float $seconds, int $precision): bool
    {
        if (! is_finite($seconds) || $seconds > self::MaxSeconds) {
            return false;
        }

        $rounded = (float) number_format($seconds, $precision, '.', '');
        $halfQuantum = 0.5 * 10 ** -$precision;

        return abs($rounded - $seconds) < $halfQuantum;
    }
}
