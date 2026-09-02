<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use RuntimeException;

/** Media facts probed only after Slate successfully executes a video-producing operation. */
final readonly class SlateVideo
{
    private function __construct(
        public ScratchPath $path,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public float $durationSeconds,
        public QualityTier $qualityTier,
    ) {}

    public static function render(
        VideoStillClip|VideoConcat|OverlayImage $operation,
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
        [$expectedWidth, $expectedHeight, $expectedFramesPerSecond, $qualityTier] = match (true) {
            $operation instanceof VideoStillClip => [
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                $operation->qualityTier,
            ],
            $operation instanceof VideoConcat => [
                $operation->width(),
                $operation->height(),
                $operation->framesPerSecond,
                $operation->qualityTier,
            ],
            $operation instanceof OverlayImage => [
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                $operation->input->qualityTier,
            ],
        };

        if (($videoStream['codec_name'] ?? null) !== 'h264'
            || ($videoStream['pix_fmt'] ?? null) !== 'yuv420p'
            || ($videoStream['color_range'] ?? null) !== 'tv'
            || $width !== $expectedWidth
            || $height !== $expectedHeight
            || $framesPerSecond !== $expectedFramesPerSecond
            || ! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new RuntimeException('Slate video output does not satisfy its probed H.264 media contract.');
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
}
