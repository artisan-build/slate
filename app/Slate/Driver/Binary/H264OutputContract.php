<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\QualityTier;

/**
 * Builds the complete H.264 and AAC output contract for video re-encode operations.
 *
 * Audio-copy operations must not use this contract because it intentionally emits AAC settings.
 */
final class H264OutputContract
{
    /**
     * Returns output arguments without an operation filtergraph.
     *
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
            '-pix_fmt',
            'yuv420p',
            CompiledOption::ColorRange->value,
            'tv',
            '-r',
            (string) $framesPerSecond,
            '-c:a',
            self::audioEncoder(),
            '-b:a',
            '192k',
        ];
    }

    /**
     * Returns the final video filter fragment that must be appended to the operation's filter chain.
     *
     * Appending this fragment converts full-range JPEG frames that would otherwise remain yuvj420p
     * into limited-range yuv420p without competing with the operation's `-vf` or `-filter_complex`.
     */
    public static function videoNormalizationFilter(): string
    {
        return 'scale=in_range=auto:out_range=tv,format=yuv420p';
    }

    public static function encoder(): string
    {
        return 'libopenh264';
    }

    public static function audioEncoder(): string
    {
        return 'aac';
    }

    private static function bitsPerPixel(QualityTier $tier): float
    {
        return match ($tier) {
            QualityTier::Intermediate => 0.16,
            QualityTier::Delivery => 0.04,
        };
    }
}
