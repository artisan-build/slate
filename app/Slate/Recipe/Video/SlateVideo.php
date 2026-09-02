<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Driver\Binary\H264OutputContract;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use RuntimeException;

/**
 * Probed media facts plus the compiler-selected quality tier after the supplied collaborators report a
 * successful render. Production provenance relies on the container-bound RecipeCompiler and Driver.
 */
final readonly class SlateVideo
{
    private const float DurationToleranceSeconds = 0.25;

    private function __construct(
        public ScratchPath $path,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public float $durationSeconds,
        public QualityTier $qualityTier,
    ) {}

    public static function render(
        VideoStillClip|VideoConcat|OverlayImage|TimedWords $operation,
        RecipeCompiler $compiler,
        Driver $driver,
    ): self {
        $output = $operation->output;
        $outputPath = $output->writablePath();

        if (is_file($outputPath) && ! unlink($outputPath)) {
            throw new RuntimeException('Unable to clear the previous Slate video output before rendering.');
        }

        $renderResult = $driver->run($compiler->compile($operation));

        if ($renderResult->failed()) {
            throw new RuntimeException('Slate video rendering failed: '.$renderResult->errorOutput);
        }

        $probeResult = $driver->run($compiler->compile(new Probe($output)));

        if ($probeResult->failed()) {
            throw new RuntimeException('Slate video output probing failed: '.$probeResult->errorOutput);
        }

        $properties = json_decode($probeResult->output, true);

        if (! is_array($properties)) {
            throw new RuntimeException('Slate video output probe returned invalid JSON.');
        }

        $videoStream = self::videoStream($properties);
        $width = $videoStream['width'] ?? null;
        $height = $videoStream['height'] ?? null;
        $framesPerSecond = self::framesPerSecond($videoStream['avg_frame_rate'] ?? null);
        $durationValue = $properties['format']['duration'] ?? null;
        $durationSeconds = is_numeric($durationValue) ? (float) $durationValue : NAN;
        [$expectedWidth, $expectedHeight, $expectedFramesPerSecond, $expectedDurationSeconds, $qualityTier] = match (true) {
            $operation instanceof VideoStillClip => [
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                $operation->durationSeconds,
                $operation->qualityTier,
            ],
            $operation instanceof VideoConcat => [
                $operation->width(),
                $operation->height(),
                $operation->framesPerSecond,
                $operation->outputDurationSeconds(),
                $operation->qualityTier,
            ],
            $operation instanceof OverlayImage => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                $operation->input->durationSeconds,
                $operation->input->qualityTier,
            ],
            $operation instanceof TimedWords => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                $operation->input->durationSeconds,
                $operation->input->qualityTier,
            ],
        };

        $contractFailures = [];
        $audioCodecs = self::audioCodecs($properties);

        if (($videoStream['codec_name'] ?? null) !== 'h264') {
            $contractFailures[] = sprintf(
                'video.codec_name expected "h264", probed %s',
                self::probeValue($videoStream['codec_name'] ?? null),
            );
        }

        if (($videoStream['pix_fmt'] ?? null) !== 'yuv420p') {
            $contractFailures[] = sprintf(
                'video.pix_fmt expected "yuv420p", probed %s',
                self::probeValue($videoStream['pix_fmt'] ?? null),
            );
        }

        if (($videoStream['color_range'] ?? null) !== 'tv') {
            $contractFailures[] = sprintf(
                'video.color_range expected "tv", probed %s',
                self::probeValue($videoStream['color_range'] ?? null),
            );
        }

        if (! in_array(H264OutputContract::audioEncoder(), $audioCodecs, true)) {
            $contractFailures[] = sprintf(
                'audio.codec_name expected to include %s, probed %s',
                self::probeValue(H264OutputContract::audioEncoder()),
                self::probeValue($audioCodecs),
            );
        }

        if ($width !== $expectedWidth) {
            $contractFailures[] = sprintf(
                'video.width expected %s, probed %s',
                self::probeValue($expectedWidth),
                self::probeValue($width),
            );
        }

        if ($height !== $expectedHeight) {
            $contractFailures[] = sprintf(
                'video.height expected %s, probed %s',
                self::probeValue($expectedHeight),
                self::probeValue($height),
            );
        }

        if ($framesPerSecond !== $expectedFramesPerSecond) {
            $contractFailures[] = sprintf(
                'video.frames_per_second expected %s, probed %s (avg_frame_rate %s)',
                self::probeValue($expectedFramesPerSecond),
                self::probeValue($framesPerSecond),
                self::probeValue($videoStream['avg_frame_rate'] ?? null),
            );
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            $contractFailures[] = sprintf(
                'format.duration expected a positive microsecond value, probed %s',
                self::probeValue($durationSeconds),
            );
        }

        if (abs($durationSeconds - $expectedDurationSeconds) > self::DurationToleranceSeconds) {
            $contractFailures[] = sprintf(
                'format.duration expected %s +/- %s seconds, probed %s seconds',
                self::probeValue($expectedDurationSeconds),
                self::probeValue(self::DurationToleranceSeconds),
                self::probeValue($durationSeconds),
            );
        }

        if ($contractFailures !== []) {
            throw new RuntimeException(
                'Slate video output does not satisfy its probed H.264 media contract: '
                .implode('; ', $contractFailures).'.',
            );
        }

        return new self($output, $width, $height, $framesPerSecond, $durationSeconds, $qualityTier);
    }

    /**
     * @param  array<mixed>  $properties
     * @return array<string, mixed>
     */
    private static function videoStream(array $properties): array
    {
        $streams = $properties['streams'] ?? null;

        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                    return $stream;
                }
            }
        }

        throw new RuntimeException('Slate video output probe did not contain a video stream.');
    }

    private static function framesPerSecond(mixed $rate): int
    {
        if (! is_string($rate) || preg_match('/^(\d+)\/(\d+)$/', $rate, $matches) !== 1) {
            throw new RuntimeException('Slate video output probe did not contain a valid frame rate.');
        }

        $numerator = (int) $matches[1];
        $denominator = (int) $matches[2];

        if ($denominator === 0 || $numerator % $denominator !== 0) {
            throw new RuntimeException('Slate video output frame rate must resolve to a whole number.');
        }

        return intdiv($numerator, $denominator);
    }

    /**
     * @param  array<mixed>  $properties
     * @return list<mixed>
     */
    private static function audioCodecs(array $properties): array
    {
        $streams = $properties['streams'] ?? null;
        $audioCodecs = [];

        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream)
                    && ($stream['codec_type'] ?? null) === 'audio') {
                    $audioCodecs[] = $stream['codec_name'] ?? null;
                }
            }
        }

        return $audioCodecs;
    }

    private static function probeValue(mixed $value): string
    {
        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
    }
}
