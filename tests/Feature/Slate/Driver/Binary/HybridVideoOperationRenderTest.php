<?php

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
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;

function requireHybridVideoRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned ffmpeg runtime unavailable; hybrid video operation lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

function renderSolidHybridVideo(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
    string $name,
    string $pixel,
): SlateVideo {
    $image = $scratch->file($name.'.ppm');
    $audio = $scratch->file($name.'.mp3');
    file_put_contents($image->writablePath(), "P3\n3 2\n255\n".implode('  ', array_fill(0, 6, $pixel))."\n");
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
        $scratch->file($name.'.mp4'),
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

function renderTemporalHybridVideo(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
    string $name,
): SlateVideo {
    $base = renderSolidHybridVideo($scratch, $compiler, $driver, $name.'-base', '255 0 0');
    $overlay = $scratch->file($name.'-overlay.ppm');
    file_put_contents(
        $overlay->writablePath(),
        "P6\n1536 1024\n255\n".str_repeat("\xFF\xFF\xFF", 1536 * 1024),
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
function probeHybridVideo(ScratchPath $path, RecipeCompiler $compiler, Driver $driver): array
{
    $result = $driver->run($compiler->compile(new Probe($path)));
    expect($result->successful())->toBeTrue($result->errorOutput);
    $properties = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($properties)) {
        throw new RuntimeException('Pinned ffprobe did not report hybrid video properties.');
    }

    return $properties;
}

function hybridVideoFrameCount(array $properties): int
{
    $videoStream = collect(data_get($properties, 'streams', []))->firstWhere('codec_type', 'video');
    $frameCount = is_array($videoStream) ? data_get($videoStream, 'nb_frames') : null;

    if (! is_numeric($frameCount)) {
        throw new RuntimeException('Pinned ffprobe did not report a hybrid video frame count.');
    }

    return (int) $frameCount;
}

/**
 * Pinned-runtime hard-cut control: substituting two copied half-segments failed on the clip-B check,
 * where a 5.0 minimum difference received 0.9844000000000008.
 */
function expectMeasurableHybridBlend(float $blend, float $from, float $to): void
{
    expect(abs($blend - $from))->toBeGreaterThan(5.0, 'Mid-transition frame matched the clip-A tail.')
        ->and(abs($blend - $to))->toBeGreaterThan(5.0, 'Mid-transition frame matched the clip-B head.');
}

/**
 * @param  list<string>  $actual
 * @param  list<string>  $expected
 */
function expectHybridFrameSequence(array $actual, array $expected, string $context): void
{
    expect(count($actual))->toBe(count($expected), $context.' frame count differed.');

    foreach ($expected as $index => $expectedHash) {
        expect($actual[$index])->toBe($expectedHash, $context." differed at output frame {$index}.");
    }
}

/**
 * Pinned-runtime controls: shifting the second source slice back one frame failed at output frame 0;
 * replaying the duplicated 0-23 + 0-47 hazard sequence failed reconstruction at output frame 24.
 */
test('stream-copy trim preserves requested frames and copy concat reconstructs the source', function (): void {
    requireHybridVideoRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $verifier = resolve(BenchmarkOutputVerifier::class);
        $source = renderTemporalHybridVideo($scratch, $compiler, $driver, 'source');
        $first = SlateVideo::render(
            new VideoTrim($source, 0, 24, $scratch->file('first.mp4')),
            $compiler,
            $driver,
        );
        $second = SlateVideo::render(
            new VideoTrim($source, 24, 48, $scratch->file('second.mp4')),
            $compiler,
            $driver,
        );
        $joined = SlateVideo::render(new VideoConcat(
            [$first, $second],
            VideoConcatMode::Copy,
            null,
            0.0,
            false,
            QualityTier::Delivery,
            24,
            $scratch->file('joined.mp4'),
        ), $compiler, $driver);
        $secondProperties = probeHybridVideo($second->path, $compiler, $driver);
        $joinedProperties = probeHybridVideo($joined->path, $compiler, $driver);
        $sourceHashes = $verifier->frameHashes($source->path);
        $firstHashes = $verifier->frameHashes($first->path);
        $secondHashes = $verifier->frameHashes($second->path);
        $joinedHashes = $verifier->frameHashes($joined->path);

        // Counts are compiler-pinned; decoded hashes detect duplicated or displaced frame content.
        expect($sourceHashes)->toHaveCount(72)
            ->and(array_unique($sourceHashes))->toHaveCount(72)
            ->and(hybridVideoFrameCount($secondProperties))->toBe(48)
            ->and(abs((float) data_get($secondProperties, 'format.duration') - 2.0))->toBeLessThanOrEqual(0.25)
            ->and(abs((float) data_get($joinedProperties, 'format.duration') - $source->durationSeconds))->toBeLessThanOrEqual(0.25);
        expectHybridFrameSequence($firstHashes, array_slice($sourceHashes, 0, 24), 'First trim');
        expectHybridFrameSequence($secondHashes, array_slice($sourceHashes, 24, 48), 'Second trim');
        expectHybridFrameSequence($joinedHashes, $sourceHashes, 'Copy-concat reconstruction');
    });
})->group('ffmpeg-render');

test('transition segment cross-fades the clip tail and head in rendered pixels', function (): void {
    requireHybridVideoRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $verifier = resolve(BenchmarkOutputVerifier::class);
        $from = renderSolidHybridVideo($scratch, $compiler, $driver, 'from', '255 0 0');
        $to = renderSolidHybridVideo($scratch, $compiler, $driver, 'to', '0 0 255');
        $transition = SlateVideo::render(new VideoTransitionSegment(
            $from,
            $to,
            24,
            QualityTier::Delivery,
            $scratch->file('transition.mp4'),
        ), $compiler, $driver);
        $properties = probeHybridVideo($transition->path, $compiler, $driver);
        $fromLuma = $verifier->frameLuma($from->path, 2.5);
        $toLuma = $verifier->frameLuma($to->path, 0.5);
        $transitionLuma = $verifier->frameLuma($transition->path, 0.5);

        expect(hybridVideoFrameCount($properties))->toBe(24)
            ->and((float) data_get($properties, 'format.duration'))->toBe(1.0);
        expectMeasurableHybridBlend($transitionLuma, $fromLuma, $toLuma);
    });
})->group('ffmpeg-render');
