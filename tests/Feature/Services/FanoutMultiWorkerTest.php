<?php

use App\Services\FanoutHybridDelivery;
use App\Services\FanoutRunCleanup;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

function requireMultiWorkerRuntime(): void
{
    if (! filter_var(getenv('SLATE_REQUIRE_MULTI_WORKER'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Multi-worker fan-out instrument is opt-in.');
    }

    expect(FfmpegLocator::fromSystem()->isProvisioned())->toBeTrue('Pinned runtime is required.');
}

function renderMultiWorkerSource(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
): SlateVideo {
    $image = $scratch->file('source.ppm');
    $audio = $scratch->file('source.mp3');
    file_put_contents($image->writablePath(), "P3\n3 2\n255\n".str_repeat("20 90 180\n", 6));
    $audioResult = $driver->run($compiler->compile(new AudioSilence(
        $audio,
        3.0,
        AudioSampleRate::Hz44100,
        AudioChannelLayout::Mono,
        AudioFormat::Mp3,
        AudioBitrate::Kbps192,
    )));
    expect($audioResult->successful())->toBeTrue($audioResult->errorOutput);

    return SlateVideo::render(new VideoStillClip(
        $image,
        $audio,
        $scratch->file('source.mp4'),
        1536,
        1024,
        24,
        3.0,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Delivery,
        AudioBitrate::Kbps192,
    ), $compiler, $driver);
}

test('records the real hostnames used by a bucket-backed worker pool', function (): void {
    requireMultiWorkerRuntime();
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
    $videoCount = max(1, (int) (getenv('SLATE_FANOUT_VIDEO_COUNT') ?: 1));
    $expectedDistinctWorkers = max(1, (int) (getenv('SLATE_EXPECT_DISTINCT_WORKERS') ?: 1));

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
        $videoCount,
        $expectedDistinctWorkers,
    ): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $source = renderMultiWorkerSource($scratch, $compiler, $driver);
        $inputs = array_fill(0, 6, $source);
        $synchronousStartedAtMs = intdiv((int) hrtime(true), 1_000_000);
        $synchronous = resolve(HybridDeliveryAssembly::class)->assemble(
            $inputs,
            24,
            QualityTier::Delivery,
            $scratch->file('synchronous.mp4'),
        );
        $synchronousWallMs = intdiv((int) hrtime(true), 1_000_000) - $synchronousStartedAtMs;
        $fanout = resolve(FanoutHybridDelivery::class);
        $runs = [];
        $fanoutStartedAtMs = intdiv((int) hrtime(true), 1_000_000);

        for ($index = 0; $index < $videoCount; $index++) {
            $runs[] = $fanout->queue($inputs, 24, QualityTier::Delivery);
        }

        $prepareAndDispatchMs = intdiv((int) hrtime(true), 1_000_000) - $fanoutStartedAtMs;

        $objectStorage = resolve(ObjectStorage::class);
        $deadline = microtime(true) + 180;
        $completionMs = [];
        $queueDrained = false;

        do {
            foreach ($runs as $index => $run) {
                if (! isset($completionMs[$index]) && $objectStorage->exists($run->resultKey())) {
                    $completionMs[$index] = intdiv((int) hrtime(true), 1_000_000) - $fanoutStartedAtMs;
                }
            }

            if (count($completionMs) === count($runs) && Queue::size() === 0) {
                $queueDrained = true;

                break;
            }

            Sleep::usleep(100_000);
        } while (microtime(true) < $deadline);

        expect($completionMs)->toHaveCount(count($runs));
        expect($queueDrained)->toBeTrue()
            ->and(Queue::size())->toBe(0);
        $queueEmptyMs = intdiv((int) hrtime(true), 1_000_000) - $fanoutStartedAtMs;
        $results = [];

        foreach ($runs as $index => $run) {
            $resultPath = $scratch->file(sprintf('result-%02d.json', $index));
            $objectStorage->stageIn($run->resultKey(), $resultPath);
            $json = file_get_contents($resultPath->readablePath());
            expect($json)->toBeString();
            $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $results[] = $result;

            expect(data_get($result, 'output.frame_count'))->toBe($synchronous['output']->frameCount);

            if ($expectedDistinctWorkers === 1) {
                expect(data_get($result, 'distinct_worker_count'))->toBe(1)
                    ->and(data_get($result, 'multi_host_observed'))->toBeFalse();
            } else {
                expect(data_get($result, 'distinct_worker_count'))->toBeGreaterThan(1)
                    ->and(data_get($result, 'multi_host_observed'))->toBeTrue();
            }
        }

        $measurement = [
            'video_count' => $videoCount,
            'operation_count_per_video' => count($runs[0]->operations),
            'synchronous_wall_ms' => $synchronousWallMs,
            'fanout_prepare_and_dispatch_ms' => $prepareAndDispatchMs,
            'fanout_completion_ms' => array_values($completionMs),
            'fanout_mean_completion_ms' => (int) round(array_sum($completionMs) / count($completionMs)),
            'fanout_all_results_visible_ms' => max($completionMs),
            'fanout_queue_empty_ms' => $queueEmptyMs,
            'worker_hostnames' => array_values(array_unique(array_merge(...array_map(
                static fn (array $result): array => $result['worker_hostnames'],
                $results,
            )))),
        ];
        sort($measurement['worker_hostnames']);
        $measurement['distinct_worker_count'] = count($measurement['worker_hostnames']);
        $measurement['multi_host_observed'] = $measurement['distinct_worker_count'] > 1;
        expect($measurement['worker_hostnames'])->toHaveCount($expectedDistinctWorkers);
        fwrite(STDOUT, 'FANOUT_MULTI_WORKER_RESULT='.json_encode($measurement, JSON_THROW_ON_ERROR).PHP_EOL);

        foreach ($runs as $run) {
            resolve(FanoutRunCleanup::class)->allObjects($run);
        }
    });
})->group('fanout-multi-worker');
