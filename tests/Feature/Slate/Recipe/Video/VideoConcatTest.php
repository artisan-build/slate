<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function concatTestVideo(ScratchDirectory $scratch, string $name, float $duration): VideoStillClip
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

test('computes xfade duration from probed six-decimal input values', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $operation = new VideoConcat(
            [
                concatTestVideo($scratch, 'first', (float) '12.345678')->video(),
                concatTestVideo($scratch, 'second', (float) '23.456789')->video(),
            ],
            VideoConcatMode::Xfade,
            VideoTransition::Fade,
            (float) '0.750000',
            true,
            QualityTier::Delivery,
            24,
            $scratch->file('joined.mp4'),
        );

        expect($operation->outputDurationSeconds())->toBe(35.052467)
            ->and($operation->video()->durationSeconds)->toBe(35.052467);
    });
});

test('rejects malformed concat lists and transition values', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $video = concatTestVideo($scratch, 'first', 2.0)->video();
        $output = $scratch->file('joined.mp4');

        expect(fn (): VideoConcat => new VideoConcat([], VideoConcatMode::Copy, null, 0.0, false, QualityTier::Delivery, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'at least two')
            ->and(fn (): VideoConcat => new VideoConcat([$video, new stdClass], VideoConcatMode::Copy, null, 0.0, false, QualityTier::Delivery, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'Slate video outputs')
            ->and(fn (): VideoConcat => new VideoConcat([$video, $video], VideoConcatMode::Copy, VideoTransition::Fade, 0.5, false, QualityTier::Delivery, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'Copy-mode')
            ->and(fn (): VideoConcat => new VideoConcat([$video, $video], VideoConcatMode::Xfade, VideoTransition::Fade, 2.0, true, QualityTier::Delivery, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'longer than the transition');
    });
});
