<?php

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
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function requireDeliveryRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned ffmpeg runtime unavailable; hybrid delivery render lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

/**
 * Temporal 3-second 1536x1024 clip: a solid base whose overlay fades in across the whole duration,
 * so the head and tail carry measurably different luma for junction-blend checks.
 */
function renderDeliveryClip(
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

function expectMeasurableDeliveryBlend(float $blend, float $from, float $to, string $context): void
{
    expect(abs($blend - $from))->toBeGreaterThan(5.0, "{$context} mid-transition frame matched the clip-A tail.")
        ->and(abs($blend - $to))->toBeGreaterThan(5.0, "{$context} mid-transition frame matched the clip-B head.");
}

/**
 * Pinned-runtime control: hash equality here is the content half of the no-re-encode proof — if any
 * trim or the splice re-encoded, the decoded body frames could no longer hash-match the sources.
 */
test('hybrid delivery matches the xfade timeline shape, splices stream-copied bodies, and cross-fades junctions in pixels', function (): void {
    requireDeliveryRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $verifier = resolve(BenchmarkOutputVerifier::class);
        $assembly = new HybridDeliveryAssembly($compiler, $driver);
        $first = renderDeliveryClip($scratch, $compiler, $driver, 'first', '0 0 0', 255, 255, 255);
        $second = renderDeliveryClip($scratch, $compiler, $driver, 'second', '0 0 255', 0, 0, 0);
        $third = renderDeliveryClip($scratch, $compiler, $driver, 'third', '0 255 0', 255, 255, 255);
        $xfadeOperation = new VideoConcat(
            [$first, $second, $third],
            VideoConcatMode::Xfade,
            VideoTransition::Fade,
            1.0,
            true,
            QualityTier::Delivery,
            24,
            $scratch->file('xfade.mp4'),
        );
        $xfade = SlateVideo::render($xfadeOperation, $compiler, $driver);
        $hybrid = $assembly->assemble([$first, $second, $third], 24, QualityTier::Delivery, $scratch->file('hybrid.mp4'));

        expect($first->frameCount)->toBe(72)
            ->and($xfade->frameCount)->toBe(168)
            ->and($hybrid['output']->frameCount)->toBe(168)
            ->and(abs($hybrid['output']->durationSeconds - $xfade->durationSeconds))->toBeLessThanOrEqual(0.25);

        $firstHashes = $verifier->frameHashes($first->path);
        $secondHashes = $verifier->frameHashes($second->path);
        $thirdHashes = $verifier->frameHashes($third->path);
        $hybridHashes = $verifier->frameHashes($hybrid['output']->path);

        expect(count($hybridHashes))->toBe(168)
            ->and(array_slice($hybridHashes, 0, 48))->toBe(array_slice($firstHashes, 0, 48), 'First body was not the stream-copied head of clip A.')
            ->and(array_slice($hybridHashes, 72, 24))->toBe(array_slice($secondHashes, 24, 24), 'Middle body was not the stream-copied interior of clip B.')
            ->and(array_slice($hybridHashes, 120, 48))->toBe(array_slice($thirdHashes, 24, 48), 'Last body was not the stream-copied tail of clip C.');

        foreach ($hybrid['operations'] as $index => $operation) {
            if ($operation instanceof VideoTransitionSegment) {
                continue;
            }

            expect($verifier->streamCopyViolations($compiler->compile($operation)))
                ->toBe([], "Hybrid operation {$index} re-encodes instead of stream-copying.");
        }

        $xfadeViolations = $verifier->streamCopyViolations($compiler->compile($xfadeOperation));
        expect(implode('; ', $xfadeViolations))->toContain('libopenh264');

        foreach (['xfade' => $xfade->path, 'hybrid' => $hybrid['output']->path] as $context => $output) {
            expectMeasurableDeliveryBlend(
                $verifier->frameLuma($output, 2.5),
                $verifier->frameLuma($first->path, 2.5),
                $verifier->frameLuma($second->path, 0.5),
                "{$context} junction 0",
            );
            expectMeasurableDeliveryBlend(
                $verifier->frameLuma($output, 4.5),
                $verifier->frameLuma($second->path, 2.5),
                $verifier->frameLuma($third->path, 0.5),
                "{$context} junction 1",
            );
        }
    });
})->group('ffmpeg-render');
