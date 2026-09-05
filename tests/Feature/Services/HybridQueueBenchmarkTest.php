<?php

use App\Services\FanoutHybridDelivery;
use App\Services\FanoutRunCleanup;
use App\Services\FfmpegBenchmark;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
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
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

const HYBRID_BENCHMARK_WIDTH = 1536;
const HYBRID_BENCHMARK_HEIGHT = 1024;
const HYBRID_BENCHMARK_FPS = 24;
const HYBRID_BENCHMARK_SEGMENT_SECONDS = 40;
const HYBRID_BENCHMARK_INPUT_COUNT = 14;
const HYBRID_BENCHMARK_TRANSITION_FRAMES = 24;
const HYBRID_BENCHMARK_EXPECTED_FRAMES = 13128;
const HYBRID_BENCHMARK_OPERATION_COUNT = 28;

function requireHybridQueueBenchmarkRuntime(): void
{
    if (! filter_var(getenv('SLATE_REQUIRE_MULTI_WORKER'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Queued hybrid benchmark is opt-in.');
    }

    expect(FfmpegLocator::fromSystem()->isProvisioned())->toBeTrue('Pinned runtime is required.');
}

/** @return resource */
function openHybridBenchmarkImage(ScratchPath $path, int $width, int $height)
{
    $stream = fopen($path->writablePath(), 'wb');

    if (! is_resource($stream)) {
        throw new RuntimeException('Unable to create a benchmark image.');
    }

    fwrite($stream, "P6\n{$width} {$height}\n255\n");

    return $stream;
}

function writeHybridBenchmarkIllustration(ScratchPath $path): void
{
    $stream = openHybridBenchmarkImage($path, HYBRID_BENCHMARK_WIDTH, HYBRID_BENCHMARK_HEIGHT);

    try {
        for ($y = 0; $y < HYBRID_BENCHMARK_HEIGHT; $y++) {
            $row = '';

            for ($x = 0; $x < HYBRID_BENCHMARK_WIDTH; $x++) {
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

function writeHybridBenchmarkBadge(ScratchPath $path): void
{
    $width = 320;
    $height = 120;
    $stream = openHybridBenchmarkImage($path, $width, $height);

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

/** @return non-empty-list<TimedWord> */
function hybridBenchmarkTimedWords(): array
{
    $wordCount = 64;
    $spacing = (HYBRID_BENCHMARK_SEGMENT_SECONDS - 0.1) / $wordCount;
    $texts = [
        'Every', 'brave', 'little', 'dreamer', 'can', 'follow', 'the', 'lantern',
        'through', 'a', 'forest', 'of', 'stories', 'and', 'find', 'home',
    ];
    $words = [];

    for ($index = 0; $index < $wordCount; $index++) {
        $enter = round(0.05 + ($index * $spacing), 6);
        $words[] = TimedWord::fromValues(
            $texts[$index % count($texts)],
            128 + (($index % 4) * 320),
            680 + ((intdiv($index, 4) % 4) * 72),
            $enter,
            round($spacing * 0.2, 6),
            round($enter + ($spacing * 0.55), 6),
            round($enter + ($spacing * 0.75), 6),
            round($spacing * 0.15, 6),
        );
    }

    return $words;
}

function renderHybridBenchmarkSource(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
): SlateVideo {
    $illustration = $scratch->file('illustration.ppm');
    $badge = $scratch->file('badge.ppm');
    $font = $scratch->file('caption-font.ttf');
    writeHybridBenchmarkIllustration($illustration);
    writeHybridBenchmarkBadge($badge);
    $configuredFont = config('slate.benchmark.font_path');

    if (! is_string($configuredFont) || ! copy($configuredFont, $font->writablePath())) {
        throw new RuntimeException('The configured benchmark font is unavailable.');
    }

    $audio = $scratch->file('segment.mp3');
    $audioResult = $driver->run($compiler->compile(new AudioSilence(
        $audio,
        HYBRID_BENCHMARK_SEGMENT_SECONDS,
        AudioSampleRate::Hz44100,
        AudioChannelLayout::Mono,
        AudioFormat::Mp3,
        AudioBitrate::Kbps192,
    )));
    expect($audioResult->successful())->toBeTrue($audioResult->errorOutput);
    $still = SlateVideo::render(new VideoStillClip(
        $illustration,
        $audio,
        $scratch->file('still.mp4'),
        HYBRID_BENCHMARK_WIDTH,
        HYBRID_BENCHMARK_HEIGHT,
        HYBRID_BENCHMARK_FPS,
        HYBRID_BENCHMARK_SEGMENT_SECONDS,
        VideoFit::ContainPad,
        VideoColor::Black,
        new KenBurnsMotion(6.0, KenBurnsDirection::In, KenBurnsEase::Sine),
        2,
        QualityTier::Intermediate,
        AudioBitrate::Kbps192,
    ), $compiler, $driver);
    $captioned = SlateVideo::render(new TimedWords(
        $still,
        $font,
        $scratch->file('captioned.mp4'),
        64,
        CaptionColor::WarmCream,
        new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
        hybridBenchmarkTimedWords(),
        CaptionEntryEase::OutBack,
        CaptionExitEase::InOutQuad,
        new CaptionDim(0.6, 0.07),
    ), $compiler, $driver);

    return SlateVideo::render(new OverlayImage(
        $captioned,
        $badge,
        $scratch->file('source.mp4'),
        1088,
        80,
        new OverlayFadeIn(37.0, 1.5),
        37.0,
    ), $compiler, $driver);
}

/** @return list<string> */
function hybridBenchmarkOptions(string $environmentName, string $default): array
{
    $value = getenv($environmentName) ?: $default;

    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

/**
 * @param  non-empty-list<SlateVideo>  $inputs
 * @return array<string, mixed>
 */
function measureHybridQueueArm(
    string $arm,
    int $videoCount,
    array $inputs,
    ScratchDirectory $scratch,
): array {
    expect(Queue::size((string) config('slate.execution.render_queue')))->toBe(0);
    $delivery = resolve(FanoutHybridDelivery::class);
    $startedAtMs = intdiv((int) hrtime(true), 1_000_000);
    $runs = [];

    for ($index = 0; $index < $videoCount; $index++) {
        $runs[] = match ($arm) {
            'single' => $delivery->queueSingle($inputs, HYBRID_BENCHMARK_TRANSITION_FRAMES, QualityTier::Intermediate),
            'fanout' => $delivery->queue($inputs, HYBRID_BENCHMARK_TRANSITION_FRAMES, QualityTier::Intermediate),
            default => throw new RuntimeException("Unknown hybrid benchmark arm: {$arm}"),
        };
    }

    $submissionMs = intdiv((int) hrtime(true), 1_000_000) - $startedAtMs;
    $objectStorage = resolve(ObjectStorage::class);
    $deadline = microtime(true) + 900;
    $completionMs = [];

    do {
        foreach ($runs as $index => $run) {
            if (! isset($completionMs[$index]) && $objectStorage->exists($run->resultKey())) {
                $completionMs[$index] = intdiv((int) hrtime(true), 1_000_000) - $startedAtMs;
            }
        }

        if (count($completionMs) === count($runs)
            && Queue::size((string) config('slate.execution.render_queue')) === 0) {
            break;
        }

        Sleep::usleep(100_000);
    } while (microtime(true) < $deadline);

    expect($completionMs)->toHaveCount(count($runs))
        ->and(Queue::size((string) config('slate.execution.render_queue')))->toBe(0);
    $poolDrainMs = intdiv((int) hrtime(true), 1_000_000) - $startedAtMs;
    $results = [];

    foreach ($runs as $index => $run) {
        $resultPath = $scratch->file(sprintf('%s-result-%02d.json', $arm, $index));
        $objectStorage->stageIn($run->resultKey(), $resultPath);
        $json = file_get_contents($resultPath->readablePath());
        expect($json)->toBeString();
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $results[] = $result;

        expect(data_get($result, 'output.frame_count'))->toBe(HYBRID_BENCHMARK_EXPECTED_FRAMES)
            ->and(data_get($result, 'operations'))->toHaveCount(HYBRID_BENCHMARK_OPERATION_COUNT - 1);

        if ($arm === 'single') {
            expect(data_get($result, 'distinct_worker_count'))->toBe(1)
                ->and(data_get($result, 'multi_host_observed'))->toBeFalse();
        } else {
            expect(data_get($result, 'distinct_worker_count'))->toBeGreaterThan(1)
                ->and(data_get($result, 'multi_host_observed'))->toBeTrue();
        }
    }

    $runtime = resolve(QueueRuntimePolicy::class)->forQueue((string) config('slate.execution.render_queue'));
    $operationDurations = [];
    $jobDurations = [];

    foreach ($results as $result) {
        array_push($operationDurations, ...array_column($result['operations'], 'processing_duration_ms'));
        $operationDurations[] = $result['final_concat']['processing_duration_ms'];
        $jobDurations[] = $arm === 'single'
            ? $result['job_processing_duration_ms']
            : max(
                $result['job_processing_duration_ms'],
                ...array_column($result['operations'], 'processing_duration_ms'),
            );
    }

    $workerHostnames = array_values(array_unique(array_merge(...array_column($results, 'worker_hostnames'))));
    sort($workerHostnames);
    $peakOperationMs = max($operationDurations);
    $peakJobMs = max($jobDurations);
    $measurement = [
        'arm' => $arm,
        'video_count' => $videoCount,
        'worker_ceiling' => 4,
        'operation_count_per_video' => HYBRID_BENCHMARK_OPERATION_COUNT,
        'submission_ms' => $submissionMs,
        'latency_ms' => array_values($completionMs),
        'mean_latency_ms' => (int) round(array_sum($completionMs) / count($completionMs)),
        'all_results_visible_ms' => max($completionMs),
        'pool_drain_ms' => $poolDrainMs,
        'worker_hostnames' => $workerHostnames,
        'distinct_worker_count' => count($workerHostnames),
        'per_video_distinct_worker_count' => array_column($results, 'distinct_worker_count'),
        'peak_operation_ms' => $peakOperationMs,
        'driver_timeout_ms' => $runtime->driverTimeoutSeconds * 1000,
        'driver_headroom_ms' => ($runtime->driverTimeoutSeconds * 1000) - $peakOperationMs,
        'peak_job_ms' => $peakJobMs,
        'visibility_timeout_ms' => $runtime->visibilityWindowSeconds * 1000,
        'visibility_headroom_ms' => ($runtime->visibilityWindowSeconds * 1000) - $peakJobMs,
        'worker' => resolve(FfmpegBenchmark::class)->workerLimits(),
    ];

    fwrite(STDOUT, 'HYBRID_QUEUE_BENCHMARK_RESULT='.json_encode($measurement, JSON_THROW_ON_ERROR).PHP_EOL);

    foreach ($runs as $run) {
        resolve(FanoutRunCleanup::class)->allObjects($run);
    }

    return $measurement;
}

test('measures queued single video jobs against queued fanout at production size', function (): void {
    requireHybridQueueBenchmarkRuntime();
    $disk = config('filesystems.disks.s3');
    expect($disk)->toBeArray();
    $client = new S3Client([
        'version' => 'latest',
        'region' => $disk['region'],
        'endpoint' => $disk['endpoint'],
        'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
        'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
    ]);

    if (! $client->doesBucketExist($disk['bucket'])) {
        $client->createBucket(['Bucket' => $disk['bucket']]);
    }

    Storage::forgetDisk((string) config('filesystems.default'));
    $arms = hybridBenchmarkOptions('SLATE_BENCHMARK_ARMS', 'single,fanout');
    $videoCounts = array_map(
        static fn (string $count): int => (int) $count,
        hybridBenchmarkOptions('SLATE_BENCHMARK_VIDEO_COUNTS', '1,3,5'),
    );
    expect($arms)->each->toBeIn(['single', 'fanout'])
        ->and($videoCounts)->each->toBeIn([1, 3, 5]);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($arms, $videoCounts): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $source = renderHybridBenchmarkSource($scratch, $compiler, $driver);
        $inputs = array_fill(0, HYBRID_BENCHMARK_INPUT_COUNT, $source);
        $plan = resolve(HybridDeliveryAssembly::class)->plan(
            $inputs,
            HYBRID_BENCHMARK_TRANSITION_FRAMES,
            QualityTier::Intermediate,
            $scratch->file('shape-only.mp4'),
        );
        expect(count($plan) + 1)->toBe(HYBRID_BENCHMARK_OPERATION_COUNT);

        foreach ($videoCounts as $videoCount) {
            foreach ($arms as $arm) {
                measureHybridQueueArm($arm, $videoCount, $inputs, $scratch);
            }
        }
    });
})->group('hybrid-queue-benchmark');
