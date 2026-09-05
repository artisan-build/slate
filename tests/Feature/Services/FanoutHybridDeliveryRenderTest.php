<?php

use App\Jobs\FinalizeFanoutRun;
use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderSingleJobHybridDelivery;
use App\Services\FanoutHybridDelivery;
use App\Services\FanoutRunCleanup;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\BenchmarkOutputVerifier;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

function requireFanoutDeliveryRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned runtime unavailable; fan-out hybrid render lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

function renderFanoutSource(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
    string $name,
    string $basePixel,
    int $overlayRed,
    int $overlayGreen,
    int $overlayBlue,
): SlateVideo {
    $image = $scratch->file($name.'-base.ppm');
    $audio = $scratch->file($name.'.mp3');
    file_put_contents($image->writablePath(), "P3\n3 2\n255\n".implode('  ', array_fill(0, 6, $basePixel))."\n");
    $audioResult = $driver->run($compiler->compile(new AudioSilence(
        $audio,
        3.0,
        AudioSampleRate::Hz44100,
        AudioChannelLayout::Mono,
        AudioFormat::Mp3,
        AudioBitrate::Kbps192,
    )));
    expect($audioResult->successful())->toBeTrue($audioResult->errorOutput);
    $base = SlateVideo::render(new VideoStillClip(
        $image,
        $audio,
        $scratch->file($name.'-base.mp4'),
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
    $overlay = $scratch->file($name.'-overlay.ppm');
    file_put_contents(
        $overlay->writablePath(),
        "P6\n1536 1024\n255\n".str_repeat(pack('CCC', $overlayRed, $overlayGreen, $overlayBlue), 1536 * 1024),
    );

    return SlateVideo::render(new OverlayImage(
        $base,
        $overlay,
        $scratch->file($name.'.mp4'),
        0,
        0,
        new OverlayFadeIn(0.0, 3.0),
        0.0,
    ), $compiler, $driver);
}

/** @return array<string, mixed> */
function probeFanoutOutput(ScratchPath $output, RecipeCompiler $compiler, Driver $driver): array
{
    $probe = $driver->run($compiler->compile(new Probe($output)));
    expect($probe->successful())->toBeTrue($probe->errorOutput);
    $properties = json_decode($probe->output, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($properties)) {
        throw new RuntimeException('Pinned probe returned invalid fan-out output properties.');
    }

    return $properties;
}

test('queued segment jobs match synchronous hybrid output through one final copy concat', function (): void {
    requireFanoutDeliveryRuntime();
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $objectStorage = resolve(ObjectStorage::class);
        $jobScratch = resolve(JobScratch::class);
        $cleanup = resolve(FanoutRunCleanup::class);
        $first = renderFanoutSource($scratch, $compiler, $driver, 'first', '0 0 0', 255, 255, 255);
        $second = renderFanoutSource($scratch, $compiler, $driver, 'second', '0 0 255', 0, 0, 0);
        $synchronous = resolve(HybridDeliveryAssembly::class)->assemble(
            [$first, $second],
            24,
            QualityTier::Delivery,
            $scratch->file('synchronous.mp4'),
        );
        $run = resolve(FanoutHybridDelivery::class)->queue(
            [$first, $second],
            24,
            QualityTier::Delivery,
        );

        foreach ($run->operations as $operation) {
            (new RenderFanoutOperation($run->id, $operation))->handle(
                $objectStorage,
                $jobScratch,
                $compiler,
                $driver,
                resolve(FanoutStorageGuard::class),
            );
        }

        (new FinalizeFanoutRun($run, false))->handle(
            $objectStorage,
            $jobScratch,
            $compiler,
            $driver,
            $cleanup,
            resolve(FanoutStorageGuard::class),
        );
        $fanoutOutput = $scratch->file('fanout.mp4');
        $resultPath = $scratch->file('result.json');
        $objectStorage->stageIn($run->outputKey(), $fanoutOutput);
        $objectStorage->stageIn($run->resultKey(), $resultPath);
        $properties = probeFanoutOutput($fanoutOutput, $compiler, $driver);
        $videoStream = collect($properties['streams'] ?? [])->firstWhere('codec_type', 'video');
        $result = json_decode(file_get_contents($resultPath->readablePath()), true, flags: JSON_THROW_ON_ERROR);
        $verifier = resolve(BenchmarkOutputVerifier::class);

        expect($videoStream)->toBeArray()
            ->and((int) ($videoStream['nb_frames'] ?? 0))->toBe($synchronous['output']->frameCount)
            ->and(abs((float) data_get($properties, 'format.duration') - $synchronous['output']->durationSeconds))
            ->toBeLessThanOrEqual(0.25)
            ->and($verifier->frameHashes($fanoutOutput))
            ->toBe($verifier->frameHashes($synchronous['output']->path))
            ->and(data_get($result, 'distinct_worker_count'))->toBe(1)
            ->and(data_get($result, 'multi_host_observed'))->toBeFalse()
            ->and(data_get($result, 'final_concat.mode'))->toBe('copy')
            ->and(data_get($result, 'operations'))->toHaveCount(3);

        $singleRun = resolve(FanoutHybridDelivery::class)->queueSingle(
            [$first, $second],
            24,
            QualityTier::Delivery,
        );
        (new RenderSingleJobHybridDelivery($singleRun))->handle(
            $objectStorage,
            $jobScratch,
            $compiler,
            $driver,
            resolve(HybridDeliveryAssembly::class),
            $cleanup,
            resolve(FanoutStorageGuard::class),
        );
        $singleOutput = $scratch->file('single-job.mp4');
        $singleResultPath = $scratch->file('single-job-result.json');
        $objectStorage->stageIn($singleRun->outputKey(), $singleOutput);
        $objectStorage->stageIn($singleRun->resultKey(), $singleResultPath);
        $singleResult = json_decode(
            file_get_contents($singleResultPath->readablePath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($verifier->frameHashes($singleOutput))
            ->toBe($verifier->frameHashes($synchronous['output']->path))
            ->and(data_get($singleResult, 'output.frame_count'))->toBe($synchronous['output']->frameCount)
            ->and(data_get($singleResult, 'distinct_worker_count'))->toBe(1)
            ->and(data_get($singleResult, 'multi_host_observed'))->toBeFalse()
            ->and(data_get($singleResult, 'job_processing_duration_ms'))->toBeGreaterThan(0)
            ->and(data_get($singleResult, 'final_concat.mode'))->toBe('copy')
            ->and(data_get($singleResult, 'operations'))->toHaveCount(3);
    });
})->group('ffmpeg-render');
