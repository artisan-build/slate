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
        $targetBitrate = (int) round($width * $height * $framesPerSecond * self::bitsPerPixel($tier));

        return [
            '-c:v',
            self::encoder(),
            '-b:v',
            (string) $targetBitrate,
            '-maxrate',
            (string) intdiv($targetBitrate * 3, 2),
            '-bufsize',
            (string) ($targetBitrate * 2),
            '-profile:v',
            'high',
            '-coder',
            'cabac',
            '-g',
            (string) ($framesPerSecond * 2),
            '-rc_mode',
            'bitrate',
            '-allow_skip_frames',
            '1',
        ];
    }

    public static function encoder(): string
    {
        return 'libopenh264';
    }

    private static function bitsPerPixel(QualityTier $tier): float
    {
        return match ($tier) {
            QualityTier::Intermediate => 0.08,
            QualityTier::Delivery => 0.04,
        };
    }
}
