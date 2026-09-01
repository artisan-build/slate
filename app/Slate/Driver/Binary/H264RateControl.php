<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\QualityTier;

final class H264RateControl
{
    /**
     * @return list<string>
     */
    public static function argumentsFor(QualityTier $tier, int $width, int $height, int $framesPerSecond): array
    {
        return self::argumentsForBitsPerPixel(self::bitsPerPixel($tier), $width, $height, $framesPerSecond);
    }

    /**
     * Generates candidate arguments for the external calibration harness.
     *
     * @return list<string>
     */
    public static function argumentsForBitsPerPixel(
        float $bitsPerPixel,
        int $width,
        int $height,
        int $framesPerSecond,
    ): array {
        $requestedBitrate = (int) round($width * $height * $framesPerSecond * $bitsPerPixel);

        return [
            '-c:v',
            self::encoder(),
            '-b:v',
            (string) $requestedBitrate,
            '-profile:v',
            'high',
            '-coder',
            'cabac',
            '-g',
            (string) ($framesPerSecond * 2),
            '-rc_mode',
            'bitrate',
            '-vf',
            'scale=in_range=auto:out_range=tv,format=yuv420p',
            '-pix_fmt',
            'yuv420p',
            '-r',
            (string) $framesPerSecond,
            '-c:a',
            'aac',
            '-b:a',
            '192k',
        ];
    }

    public static function encoder(): string
    {
        return 'libopenh264';
    }

    private static function bitsPerPixel(QualityTier $tier): float
    {
        return match ($tier) {
            QualityTier::Intermediate => 0.16,
            QualityTier::Delivery => 0.04,
        };
    }
}
