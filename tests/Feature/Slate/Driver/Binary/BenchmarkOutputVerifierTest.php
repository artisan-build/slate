<?php

use App\Services\FfmpegDiagnostics;
use App\Slate\Driver\Binary\BenchmarkOutputVerifier;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\VideoConcatCompiler;
use App\Slate\Driver\Binary\Operation\VideoTrimCompiler;
use App\Slate\Driver\FakeDriver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function streamCopyTestClip(ScratchDirectory $scratch, string $name, float $duration): VideoStillClip
{
    return new VideoStillClip(
        $scratch->file($name.'.png'),
        $scratch->file($name.'.mp3'),
        $scratch->file($name.'.mp4'),
        1536,
        1024,
        24,
        $duration,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Intermediate,
        AudioBitrate::Kbps192,
    );
}

/**
 * Pinned-runtime control for the no-re-encode instrument: the detector must stay green on the two
 * copy paths the hybrid lever depends on, and provably go red on the real xfade re-encode path.
 * Frame-hash assertions cannot supply this guarantee because hashes are content-only.
 */
test('stream-copy detector accepts copy recipes and rejects re-encode recipes', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $locator = new FfmpegLocator('Linux', 'arm64', '/runtime');
        $verifier = new BenchmarkOutputVerifier(new FakeDriver, new FfmpegDiagnostics);
        $first = fakeRenderedSlateVideo(streamCopyTestClip($scratch, 'first', 3.0));
        $second = fakeRenderedSlateVideo(streamCopyTestClip($scratch, 'second', 3.0));
        $trimRecipe = (new VideoTrimCompiler($locator))
            ->compile(new VideoTrim($first, 24, 48, $scratch->file('body.mp4')));
        $copyConcatRecipe = (new VideoConcatCompiler(
            $locator,
            new FilterGraphCompiler,
        ))->compile(new VideoConcat(
            [$first, $second],
            VideoConcatMode::Copy,
            null,
            0.0,
            false,
            QualityTier::Intermediate,
            24,
            $scratch->file('spliced.mp4'),
        ));
        $xfadeRecipe = (new VideoConcatCompiler(
            $locator,
            new FilterGraphCompiler,
        ))->compile(new VideoConcat(
            [$first, $second],
            VideoConcatMode::Xfade,
            VideoTransition::Fade,
            1.0,
            true,
            QualityTier::Delivery,
            24,
            $scratch->file('joined.mp4'),
        ));

        expect($verifier->streamCopyViolations($trimRecipe))->toBe([])
            ->and($verifier->streamCopyViolations($copyConcatRecipe))->toBe([])
            ->and($verifier->streamCopyViolations($xfadeRecipe))->not->toBe([])
            ->and(implode('; ', $verifier->streamCopyViolations($xfadeRecipe)))->toContain('libopenh264');
    });
});
