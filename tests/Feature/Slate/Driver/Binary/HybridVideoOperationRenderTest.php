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

function expectMeasurableHybridBlend(float $blend, float $from, float $to): void
{
    expect(abs($blend - $from))->toBeGreaterThan(5.0, 'Mid-transition frame matched the clip-A tail.')
        ->and(abs($blend - $to))->toBeGreaterThan(5.0, 'Mid-transition frame matched the clip-B head.');
}

test('stream-copy trim is frame-exact and round-trips through copy concat', function (): void {
    requireHybridVideoRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $source = renderSolidHybridVideo($scratch, $compiler, $driver, 'source', '255 0 0');
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

        expect(hybridVideoFrameCount($secondProperties))->toBe(48)
            ->and(abs((float) data_get($secondProperties, 'format.duration') - 2.0))->toBeLessThanOrEqual(0.25)
            ->and(hybridVideoFrameCount($joinedProperties))->toBe($source->frameCount)
            ->and($joined->frameCount)->toBe($source->frameCount);
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
