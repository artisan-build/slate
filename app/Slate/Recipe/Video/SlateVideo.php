<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Driver\Binary\H264OutputContract;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Fanout\StoredVideo;
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
        public int $frameCount,
        public float $durationSeconds,
        public QualityTier $qualityTier,
        public ?int $keyframeIntervalFrames,
    ) {}

    public static function render(
        VideoStillClip|VideoTrim|VideoTransitionSegment|VideoConcat|OverlayImage|TimedWords $operation,
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

        [$expectedWidth, $expectedHeight, $expectedFramesPerSecond, $expectedFrameCount, $expectedDurationSeconds, $qualityTier, $keyframeIntervalFrames] = match (true) {
            $operation instanceof VideoStillClip => [
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                null,
                $operation->durationSeconds,
                $operation->qualityTier,
                H264OutputContract::keyframeIntervalFrames($operation->framesPerSecond),
            ],
            $operation instanceof VideoConcat => [
                $operation->width(),
                $operation->height(),
                $operation->framesPerSecond,
                $operation->mode === VideoConcatMode::Copy
                    ? array_sum(array_map(static fn (self $input): int => $input->frameCount, $operation->inputs))
                    : null,
                $operation->outputDurationSeconds(),
                $operation->qualityTier,
                $operation->mode === VideoConcatMode::Copy
                    ? self::copyKeyframeInterval($operation)
                    : H264OutputContract::keyframeIntervalFrames($operation->framesPerSecond),
            ],
            $operation instanceof OverlayImage => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                null,
                $operation->input->durationSeconds,
                $operation->input->qualityTier,
                H264OutputContract::keyframeIntervalFrames($operation->input->framesPerSecond),
            ],
            $operation instanceof TimedWords => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                null,
                $operation->input->durationSeconds,
                $operation->input->qualityTier,
                H264OutputContract::keyframeIntervalFrames($operation->input->framesPerSecond),
            ],
            $operation instanceof VideoTrim => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                $operation->frameCount,
                $operation->durationSeconds(),
                $operation->input->qualityTier,
                $operation->input->keyframeIntervalFrames,
            ],
            $operation instanceof VideoTransitionSegment => [
                $operation->from->width,
                $operation->from->height,
                $operation->framesPerSecond(),
                $operation->frameCount,
                $operation->durationSeconds(),
                $operation->qualityTier,
                H264OutputContract::keyframeIntervalFrames($operation->framesPerSecond()),
            ],
        };

        return self::probeExpected(
            $output,
            $expectedWidth,
            $expectedHeight,
            $expectedFramesPerSecond,
            $expectedFrameCount,
            $expectedDurationSeconds,
            $qualityTier,
            $keyframeIntervalFrames,
            $compiler,
            $driver,
        );
    }

    /**
     * Reconstitutes a bucket-backed video only after probing the bytes staged into this invocation's
     * scratch directory against the immutable metadata carried by the queue recipe.
     */
    public static function fromStored(
        StoredVideo $storedVideo,
        ScratchPath $localPath,
        RecipeCompiler $compiler,
        Driver $driver,
    ): self {
        return self::probeExpected(
            $localPath,
            $storedVideo->width,
            $storedVideo->height,
            $storedVideo->framesPerSecond,
            $storedVideo->frameCount,
            $storedVideo->durationSeconds,
            $storedVideo->qualityTier,
            $storedVideo->keyframeIntervalFrames,
            $compiler,
            $driver,
        );
    }

    private static function probeExpected(
        ScratchPath $output,
        int $expectedWidth,
        int $expectedHeight,
        int $expectedFramesPerSecond,
        ?int $expectedFrameCount,
        float $expectedDurationSeconds,
        QualityTier $qualityTier,
        ?int $keyframeIntervalFrames,
        RecipeCompiler $compiler,
        Driver $driver,
    ): self {
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
        $frameCountValue = $videoStream['nb_frames'] ?? null;
        $frameCount = self::frameCount($frameCountValue);
        $durationValue = $properties['format']['duration'] ?? null;
        $durationSeconds = is_numeric($durationValue) ? (float) $durationValue : NAN;

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

        if ($frameCount < 1) {
            $contractFailures[] = sprintf(
                'video.nb_frames expected a positive integer, probed %s',
                self::probeValue($frameCountValue),
            );
        } elseif ($expectedFrameCount !== null && $frameCount !== $expectedFrameCount) {
            $contractFailures[] = sprintf(
                'video.nb_frames expected %s, probed %s',
                self::probeValue($expectedFrameCount),
                self::probeValue($frameCount),
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

        return new self(
            $output,
            $width,
            $height,
            $framesPerSecond,
            $frameCount,
            $durationSeconds,
            $qualityTier,
            $keyframeIntervalFrames,
        );
    }

    private static function copyKeyframeInterval(VideoConcat $operation): ?int
    {
        $interval = $operation->inputs[0]->keyframeIntervalFrames;

        if ($interval === null) {
            return null;
        }

        foreach ($operation->inputs as $index => $input) {
            if ($input->keyframeIntervalFrames !== $interval) {
                return null;
            }

            if ($index < count($operation->inputs) - 1 && $input->frameCount % $interval !== 0) {
                return null;
            }
        }

        return $interval;
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

    private static function frameCount(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return 0;
        }

        return (int) $value;
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
