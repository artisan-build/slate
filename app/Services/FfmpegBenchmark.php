<?php

declare(strict_types=1);

namespace App\Services;

use App\Slate\Driver\Binary\BenchmarkOutputVerifier;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Caption\CaptionColor;
use App\Slate\Recipe\Caption\CaptionDim;
use App\Slate\Recipe\Caption\CaptionEntryEase;
use App\Slate\Recipe\Caption\CaptionExitEase;
use App\Slate\Recipe\Caption\CaptionShadow;
use App\Slate\Recipe\Caption\CaptionShadowColor;
use App\Slate\Recipe\Caption\TimedWord;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use JsonException;
use RuntimeException;

class FfmpegBenchmark
{
    private const int Width = 1536;

    private const int Height = 1024;

    private const int FramesPerSecond = 24;

    private const float TransitionDurationSeconds = 0.75;

    private const int PublishedTargetDurationSeconds = 540;

    private const int PublishedSegmentDurationSeconds = 40;

    private const int PublishedOutputDurationMs = 550250;

    private const int PublishedOutputFrameCount = 13206;

    public function __construct(
        private readonly JobScratch $jobScratch,
        private readonly RecipeCompiler $compiler,
        private readonly Driver $driver,
        private readonly BenchmarkOutputVerifier $outputVerifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $runId, int $targetDurationSeconds, int $segmentDurationSeconds): array
    {
        if ($targetDurationSeconds < 1
            || $segmentDurationSeconds < 1
            || $segmentDurationSeconds > $targetDurationSeconds) {
            throw new RuntimeException('Benchmark durations must be positive and the segment cannot exceed the target.');
        }

        return $this->jobScratch->run(function (ScratchDirectory $scratch) use (
            $runId,
            $targetDurationSeconds,
            $segmentDurationSeconds,
        ): array {
            $assetStartedAtMs = $this->monotonicMilliseconds();
            $illustration = $scratch->file('illustration.ppm');
            $badge = $scratch->file('badge.ppm');
            $font = $scratch->file('caption-font.ttf');
            $this->writeIllustration($illustration);
            $this->writeBadge($badge);
            $this->stageFont($font);
            $assetPreparationMs = $this->monotonicMilliseconds() - $assetStartedAtMs;
            $startedAtMs = $this->monotonicMilliseconds();
            $measurements = [];

            $audio = $scratch->file('segment.mp3');
            $audioOperation = new AudioSilence(
                $audio,
                (float) $segmentDurationSeconds,
                AudioSampleRate::Hz44100,
                AudioChannelLayout::Mono,
                AudioFormat::Mp3,
                AudioBitrate::Kbps192,
            );
            $audioStartedAtMs = $this->monotonicMilliseconds();
            $audioResult = $this->driver->run($this->compiler->compile($audioOperation));

            if ($audioResult->failed()) {
                throw new RuntimeException('Benchmark audio rendering failed: '.$audioResult->errorOutput);
            }

            $measurements[] = $this->measurement(
                $audioOperation,
                (float) $segmentDurationSeconds,
                $this->monotonicMilliseconds() - $audioStartedAtMs,
                $audio,
            );

            [$stillVideo, $stillMeasurement] = $this->renderVideo(new VideoStillClip(
                $illustration,
                $audio,
                $scratch->file('still.mp4'),
                self::Width,
                self::Height,
                self::FramesPerSecond,
                (float) $segmentDurationSeconds,
                VideoFit::ContainPad,
                VideoColor::Black,
                new KenBurnsMotion(6.0, KenBurnsDirection::In, KenBurnsEase::Sine),
                2,
                QualityTier::Intermediate,
                AudioBitrate::Kbps192,
            ));
            $measurements[] = $stillMeasurement;
            $words = $this->timedWords((float) $segmentDurationSeconds);
            $captionOperation = new TimedWords(
                $stillVideo,
                $font,
                $scratch->file('captioned.mp4'),
                64,
                CaptionColor::WarmCream,
                new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
                $words,
                CaptionEntryEase::OutBack,
                CaptionExitEase::InOutQuad,
                new CaptionDim(0.6, 0.07),
            );
            $compiledDrawtextCount = $this->outputVerifier->compiledDrawtextCount(
                $captionOperation,
                $this->compiler,
            );

            if ($compiledDrawtextCount !== count($words)) {
                throw new RuntimeException(sprintf(
                    'Benchmark caption compiler emitted %d drawtext filters for %d timed words.',
                    $compiledDrawtextCount,
                    count($words),
                ));
            }

            [$captionedVideo, $captionMeasurement] = $this->renderVideo($captionOperation);
            $measurements[] = $captionMeasurement;
            $firstWord = $words[0];
            $baselineLuma = $this->outputVerifier->captionRegionLuma(
                $captionedVideo->path,
                max(0.001, $firstWord->enterSeconds / 2),
            );
            $activeLuma = $this->outputVerifier->captionRegionLuma(
                $captionedVideo->path,
                round(($firstWord->enterSeconds + $firstWord->spokenEndSeconds) / 2, 6),
            );

            if ($activeLuma <= $baselineLuma + 0.1) {
                throw new RuntimeException(sprintf(
                    'Benchmark captions did not measurably change their output region (baseline YAVG %.4f, active YAVG %.4f).',
                    $baselineLuma,
                    $activeLuma,
                ));
            }

            $overlayFadeDuration = min(1.5, max(0.1, $segmentDurationSeconds / 4));
            $overlayStart = max(0.0, $segmentDurationSeconds - ($overlayFadeDuration * 2));
            [$overlaidVideo, $overlayMeasurement] = $this->renderVideo(new OverlayImage(
                $captionedVideo,
                $badge,
                $scratch->file('overlaid.mp4'),
                1088,
                80,
                new OverlayFadeIn($overlayStart, $overlayFadeDuration),
                $overlayStart,
            ));
            $measurements[] = $overlayMeasurement;
            $transitionDuration = min(self::TransitionDurationSeconds, $segmentDurationSeconds / 4);
            $concatInputCount = $this->concatInputCount(
                $targetDurationSeconds,
                $segmentDurationSeconds,
                $transitionDuration,
            );
            [$finalVideo, $concatMeasurement] = $this->renderVideo(new VideoConcat(
                array_fill(0, $concatInputCount, $overlaidVideo),
                VideoConcatMode::Xfade,
                VideoTransition::Fade,
                $transitionDuration,
                true,
                QualityTier::Delivery,
                self::FramesPerSecond,
                $scratch->file('benchmark.mp4'),
            ));
            $measurements[] = $concatMeasurement;
            $output = $this->assertFinalOutput(
                $finalVideo,
                $targetDurationSeconds,
                $segmentDurationSeconds,
            );
            $finishedAtMs = $this->monotonicMilliseconds();
            $summary = self::summarizeMeasurements(
                $measurements,
                $output['duration_ms'],
                $finishedAtMs - $startedAtMs,
                (int) config('slate.benchmark.visibility_timeout_seconds'),
            );

            return [
                'run_id' => $runId,
                'workload' => [
                    'source_type' => 'procedural_illustration',
                    'real_footage_throughput' => 'UNVERIFIED',
                    'width' => self::Width,
                    'height' => self::Height,
                    'frames_per_second' => self::FramesPerSecond,
                    'still_supersample' => 2,
                    'segment_duration_seconds' => $segmentDurationSeconds,
                    'target_duration_seconds' => $targetDurationSeconds,
                    'concat_input_count' => $concatInputCount,
                    'caption_word_count' => count($words),
                    'intermediate_quality_tier' => QualityTier::Intermediate->value,
                    'final_quality_tier' => QualityTier::Delivery->value,
                    'production_operation_timeout_seconds' => (int) config('slate.driver.timeout_seconds'),
                    'instrument_operation_timeout_seconds' => (int) config('slate.benchmark.instrument_operation_timeout_seconds'),
                ],
                'asset_preparation_ms' => $assetPreparationMs,
                'measurements' => $measurements,
                'output' => $output,
                'captions' => [
                    'baseline_region_luma' => $baselineLuma,
                    'active_region_luma' => $activeLuma,
                    'luma_delta' => round($activeLuma - $baselineLuma, 4),
                    'compiled_drawtext_count' => $compiledDrawtextCount,
                    'pixels_verified' => true,
                ],
                'summary' => $summary,
                'worker' => $this->workerLimits(),
            ];
        });
    }

    /**
     * @param  list<array{operation: string, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int}>  $measurements
     * @return array{operation_media_ms: int, operation_processing_ms: int, operation_realtime_factor: float, segment_media_ms: int, segment_processing_ms: int, segment_realtime_factor: float, caption_media_ms: int, caption_processing_ms: int, caption_realtime_factor: float, concat_media_ms: int, concat_processing_ms: int, concat_realtime_factor: float, output_duration_ms: int, total_wall_ms: int, end_to_end_realtime_factor: float, peak_operation: string, peak_operation_ms: int, driver_timeout_ms: int, driver_headroom_ms: int, visibility_timeout_ms: int, visibility_headroom_ms: int}
     */
    public static function summarizeMeasurements(
        array $measurements,
        int $outputDurationMs,
        int $totalWallMs,
        int $visibilityTimeoutSeconds,
        ?int $driverTimeoutSeconds = null,
    ): array {
        $operationMediaMs = array_sum(array_column($measurements, 'media_duration_ms'));
        $operationProcessingMs = array_sum(array_column($measurements, 'processing_duration_ms'));
        $captionMeasurements = array_values(array_filter(
            $measurements,
            static fn (array $measurement): bool => $measurement['operation'] === 'captions.timed_words',
        ));
        $segmentMeasurements = array_values(array_filter(
            $measurements,
            static fn (array $measurement): bool => $measurement['operation'] !== 'video.concat',
        ));
        $concatMeasurements = array_values(array_filter(
            $measurements,
            static fn (array $measurement): bool => $measurement['operation'] === 'video.concat',
        ));

        if ($captionMeasurements === [] || $segmentMeasurements === [] || $concatMeasurements === []) {
            throw new RuntimeException('Benchmark measurements must include segment, caption, and concat operations.');
        }
        $captionMediaMs = array_sum(array_column($captionMeasurements, 'media_duration_ms'));
        $captionProcessingMs = array_sum(array_column($captionMeasurements, 'processing_duration_ms'));
        $segmentMediaMs = max(array_column($segmentMeasurements, 'media_duration_ms'));
        $segmentProcessingMs = array_sum(array_column($segmentMeasurements, 'processing_duration_ms'));
        $concatMediaMs = array_sum(array_column($concatMeasurements, 'media_duration_ms'));
        $concatProcessingMs = array_sum(array_column($concatMeasurements, 'processing_duration_ms'));
        $peak = $measurements[0];

        foreach ($measurements as $measurement) {
            if ($measurement['processing_duration_ms'] > $peak['processing_duration_ms']) {
                $peak = $measurement;
            }
        }

        $visibilityTimeoutMs = $visibilityTimeoutSeconds * 1000;
        $driverTimeoutMs = ($driverTimeoutSeconds ?? (int) config('slate.driver.timeout_seconds')) * 1000;

        return [
            'operation_media_ms' => $operationMediaMs,
            'operation_processing_ms' => $operationProcessingMs,
            'operation_realtime_factor' => round($operationMediaMs / max(1, $operationProcessingMs), 3),
            'segment_media_ms' => $segmentMediaMs,
            'segment_processing_ms' => $segmentProcessingMs,
            'segment_realtime_factor' => round($segmentMediaMs / max(1, $segmentProcessingMs), 3),
            'caption_media_ms' => $captionMediaMs,
            'caption_processing_ms' => $captionProcessingMs,
            'caption_realtime_factor' => round($captionMediaMs / max(1, $captionProcessingMs), 3),
            'concat_media_ms' => $concatMediaMs,
            'concat_processing_ms' => $concatProcessingMs,
            'concat_realtime_factor' => round($concatMediaMs / max(1, $concatProcessingMs), 3),
            'output_duration_ms' => $outputDurationMs,
            'total_wall_ms' => max(1, $totalWallMs),
            'end_to_end_realtime_factor' => round($outputDurationMs / max(1, $totalWallMs), 3),
            'peak_operation' => $peak['operation'],
            'peak_operation_ms' => $peak['processing_duration_ms'],
            'driver_timeout_ms' => $driverTimeoutMs,
            'driver_headroom_ms' => $driverTimeoutMs - $peak['processing_duration_ms'],
            'visibility_timeout_ms' => $visibilityTimeoutMs,
            'visibility_headroom_ms' => $visibilityTimeoutMs - $peak['processing_duration_ms'],
        ];
    }

    /**
     * @return array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}
     */
    public function workerLimits(): array
    {
        $memoryMax = $this->readCgroup('/sys/fs/cgroup/memory.max');
        $cpuMax = $this->readCgroup('/sys/fs/cgroup/cpu.max');
        $cpuParts = preg_split('/\s+/', $cpuMax) ?: [];
        $cpuCores = null;

        if (count($cpuParts) === 2 && $cpuParts[0] !== 'max' && (int) $cpuParts[1] > 0) {
            $cpuCores = round((int) $cpuParts[0] / (int) $cpuParts[1], 3);
        }

        return [
            'hostname' => gethostname() ?: 'unknown',
            'memory_max' => $memoryMax,
            'memory_bytes' => $memoryMax === 'max' || $memoryMax === 'unavailable' ? null : (int) $memoryMax,
            'cpu_max' => $cpuMax,
            'cpu_cores' => $cpuCores,
        ];
    }

    /**
     * @return array{SlateVideo, array{operation: string, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int}}
     */
    private function renderVideo(VideoStillClip|VideoConcat|OverlayImage|TimedWords $operation): array
    {
        $startedAtMs = $this->monotonicMilliseconds();
        $video = SlateVideo::render($operation, $this->compiler, $this->driver);

        return [
            $video,
            $this->measurement(
                $operation,
                $video->durationSeconds,
                $this->monotonicMilliseconds() - $startedAtMs,
                $video->path,
            ),
        ];
    }

    /**
     * @return array{operation: string, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int}
     */
    private function measurement(
        Operation $operation,
        float $mediaDurationSeconds,
        int $processingDurationMs,
        ScratchPath $output,
    ): array {
        return [
            'operation' => $operation->name()->value,
            'media_duration_ms' => (int) round($mediaDurationSeconds * 1000),
            'processing_duration_ms' => $processingDurationMs,
            'output_size_bytes' => $this->fileSize($output->readablePath()),
        ];
    }

    /**
     * @return non-empty-list<TimedWord>
     */
    private function timedWords(float $durationSeconds): array
    {
        $wordCount = min(64, max(1, (int) floor(($durationSeconds - 0.1) / 0.45)));
        $spacing = ($durationSeconds - 0.1) / $wordCount;
        $texts = [
            'Every', 'brave', 'little', 'dreamer', 'can', 'follow', 'the', 'lantern',
            'through', 'a', 'forest', 'of', 'stories', 'and', 'find', 'home',
        ];
        $words = [];

        for ($index = 0; $index < $wordCount; $index++) {
            $enter = round(0.05 + ($index * $spacing), 6);
            $enterDuration = round($spacing * 0.2, 6);
            $spokenEnd = round($enter + ($spacing * 0.55), 6);
            $clear = round($enter + ($spacing * 0.75), 6);
            $exitDuration = round($spacing * 0.15, 6);
            $words[] = TimedWord::fromValues(
                $texts[$index % count($texts)],
                128 + (($index % 4) * 320),
                680 + ((intdiv($index, 4) % 4) * 72),
                $enter,
                $enterDuration,
                $spokenEnd,
                $clear,
                $exitDuration,
            );
        }

        return $words;
    }

    private function concatInputCount(
        int $targetDurationSeconds,
        int $segmentDurationSeconds,
        float $transitionDurationSeconds,
    ): int {
        $effectiveSegmentSeconds = $segmentDurationSeconds - $transitionDurationSeconds;

        return max(2, (int) round(
            ($targetDurationSeconds - $transitionDurationSeconds) / $effectiveSegmentSeconds,
        ));
    }

    /**
     * @return array{duration_ms: int, frame_count: int, expected_frame_count: int, published_shape_verified: bool, width: int, height: int, frames_per_second: int, output_size_bytes: int}
     */
    private function assertFinalOutput(
        SlateVideo $video,
        int $targetDurationSeconds,
        int $segmentDurationSeconds,
    ): array {
        $result = $this->driver->run($this->compiler->compile(new Probe($video->path)));

        if ($result->failed()) {
            throw new RuntimeException('Benchmark final output probe failed: '.$result->errorOutput);
        }

        try {
            $properties = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Benchmark final output probe returned invalid JSON.', previous: $exception);
        }

        if (! is_array($properties)) {
            throw new RuntimeException('Benchmark final output probe returned invalid media properties.');
        }

        $streams = $properties['streams'] ?? null;
        $videoStream = null;

        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                    $videoStream = $stream;

                    break;
                }
            }
        }

        $frameCount = is_array($videoStream) && is_numeric($videoStream['nb_frames'] ?? null)
            ? (int) $videoStream['nb_frames']
            : null;
        $expectedFrameCount = (int) round($video->durationSeconds * $video->framesPerSecond);
        $durationMs = (int) round($video->durationSeconds * 1000);

        if ($frameCount === null || abs($frameCount - $expectedFrameCount) > 1) {
            throw new RuntimeException(sprintf(
                'Benchmark final frame count does not match its probed duration (expected %d, probed %s).',
                $expectedFrameCount,
                $frameCount === null ? 'unavailable' : (string) $frameCount,
            ));
        }

        $publishedWorkload = $targetDurationSeconds === self::PublishedTargetDurationSeconds
            && $segmentDurationSeconds === self::PublishedSegmentDurationSeconds;

        if ($publishedWorkload
            && ($durationMs !== self::PublishedOutputDurationMs
                || $frameCount !== self::PublishedOutputFrameCount)) {
            throw new RuntimeException(sprintf(
                'Benchmark default output must be exactly %dms and %d frames; probed %dms and %d frames.',
                self::PublishedOutputDurationMs,
                self::PublishedOutputFrameCount,
                $durationMs,
                $frameCount,
            ));
        }

        return [
            'duration_ms' => $durationMs,
            'frame_count' => $frameCount,
            'expected_frame_count' => $expectedFrameCount,
            'published_shape_verified' => $publishedWorkload,
            'width' => $video->width,
            'height' => $video->height,
            'frames_per_second' => $video->framesPerSecond,
            'output_size_bytes' => $this->fileSize($video->path->readablePath()),
        ];
    }

    private function writeIllustration(ScratchPath $path): void
    {
        $stream = $this->openImage($path, self::Width, self::Height);

        try {
            for ($y = 0; $y < self::Height; $y++) {
                $row = '';

                for ($x = 0; $x < self::Width; $x++) {
                    $texture = (($x >> 5) + ($y >> 5)) % 12;
                    $row .= pack('CCC', 18 + $texture, 24 + ($texture * 2), 38 + ($texture * 2));
                }

                if (fwrite($stream, $row) !== strlen($row)) {
                    throw new RuntimeException('Unable to write the benchmark illustration.');
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function writeBadge(ScratchPath $path): void
    {
        $width = 320;
        $height = 120;
        $stream = $this->openImage($path, $width, $height);

        try {
            for ($y = 0; $y < $height; $y++) {
                $row = '';

                for ($x = 0; $x < $width; $x++) {
                    $inside = $x > 8 && $x < $width - 8 && $y > 8 && $y < $height - 8;
                    $row .= $inside ? pack('CCC', 234, 217, 191) : pack('CCC', 55, 42, 35);
                }

                if (fwrite($stream, $row) !== strlen($row)) {
                    throw new RuntimeException('Unable to write the benchmark badge.');
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function openImage(ScratchPath $path, int $width, int $height)
    {
        $stream = fopen($path->writablePath(), 'wb');

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to create a benchmark image.');
        }

        fwrite($stream, "P6\n{$width} {$height}\n255\n");

        return $stream;
    }

    private function stageFont(ScratchPath $font): void
    {
        $configuredFont = config('slate.benchmark.font_path');

        if (! is_string($configuredFont) || ! is_file($configuredFont)) {
            throw new RuntimeException('The configured benchmark font is unavailable.');
        }

        if (! copy($configuredFont, $font->writablePath())) {
            throw new RuntimeException('Unable to stage the benchmark font.');
        }
    }

    private function fileSize(string $path): int
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Unable to measure file size: {$path}");
        }

        return $size;
    }

    private function readCgroup(string $path): string
    {
        $value = is_file($path) ? file_get_contents($path) : false;

        return is_string($value) ? trim($value) : 'unavailable';
    }

    private function monotonicMilliseconds(): int
    {
        return intdiv((int) hrtime(true), 1_000_000);
    }
}
