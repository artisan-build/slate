<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\QualityTier;

/**
 * Builds the complete H.264 and AAC output contract for video re-encode operations.
 *
 * Audio-copy operations must not use this contract because it intentionally emits AAC settings.
 * The tier calibration and 204.6-second baseline used the former two-second GOP; both must be
 * re-measured before comparing them with outputs from this one-second keyframe contract.
 */
final class H264OutputContract
{
    private const int KeyframeIntervalSeconds = 1;

    /**
     * Returns output arguments without an operation filtergraph.
     *
     * @return list<string>
     */
    public static function argumentsFor(QualityTier $tier, int $width, int $height, int $framesPerSecond): array
    {
        return self::argumentsForBitsPerPixel(self::bitsPerPixel($tier), $width, $height, $framesPerSecond);
    }

    /** @return list<string> */
    public static function videoArgumentsFor(QualityTier $tier, int $width, int $height, int $framesPerSecond): array
    {
        return self::videoArgumentsForBitsPerPixel(self::bitsPerPixel($tier), $width, $height, $framesPerSecond);
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
        return [
            ...self::videoArgumentsForBitsPerPixel($bitsPerPixel, $width, $height, $framesPerSecond),
            '-c:a',
            self::audioEncoder(),
            '-b:a',
            '192k',
        ];
    }

    /** @return list<string> */
    private static function videoArgumentsForBitsPerPixel(
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
            (string) self::keyframeIntervalFrames($framesPerSecond),
            CompiledOption::ForceKeyFrames->value,
            'expr:gte(t,n_forced*'.self::KeyframeIntervalSeconds.')',
            '-rc_mode',
            'bitrate',
            '-pix_fmt',
            'yuv420p',
            CompiledOption::ColorRange->value,
            'tv',
            CompiledOption::VideoBitstreamFilter->value,
            'h264_metadata=video_full_range_flag=0',
            '-r',
            (string) $framesPerSecond,
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

    public static function keyframeIntervalFrames(int $framesPerSecond): int
    {
        return $framesPerSecond * self::KeyframeIntervalSeconds;
    }

    private static function bitsPerPixel(QualityTier $tier): float
    {
        return match ($tier) {
            QualityTier::Intermediate => 0.16,
            QualityTier::Delivery => 0.04,
        };
    }
}
