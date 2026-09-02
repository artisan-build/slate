<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

/**
 * Keeps compiler arithmetic exact while allowing substantially more than the v1 nine-minute workload.
 */
final class AudioDuration
{
    public const float MaxSeconds = 86400.0;

    public static function isPositiveMicrosecondDuration(float $seconds): bool
    {
        return $seconds > 0
            && self::isMicrosecondDuration($seconds);
    }

    public static function isNonNegativeMicrosecondDuration(float $seconds): bool
    {
        return $seconds >= 0
            && self::isMicrosecondDuration($seconds);
    }

    public static function isNonNegativeMillisecondDuration(float $seconds): bool
    {
        return $seconds >= 0
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

        return (float) number_format($seconds, $precision, '.', '') === $seconds;
    }
}
