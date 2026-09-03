<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function trimRecipeVideo(ScratchDirectory $scratch, string $name, float $durationSeconds): VideoStillClip
{
    return new VideoStillClip(
        $scratch->file($name.'.png'),
        $scratch->file($name.'.mp3'),
        $scratch->file($name.'.mp4'),
        1536,
        1024,
        24,
        $durationSeconds,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Delivery,
        AudioBitrate::Kbps192,
    );
}

test('accepts a frame range beginning on the guaranteed keyframe grid', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = fakeRenderedSlateVideo(trimRecipeVideo($scratch, 'source', 3.0));

        $operation = new VideoTrim($input, 24, 48, $scratch->file('trimmed.mp4'));

        expect($operation->name()->value)->toBe('video.trim')
            ->and($operation->startSeconds())->toBe(1.0)
            ->and($operation->durationSeconds())->toBe(2.0);

        expect(fn () => fakeRenderedSlateVideo($operation, videoStreamOverrides: ['nb_frames' => '47']))
            ->toThrow(RuntimeException::class, 'video.nb_frames expected 48, probed 47');
    });
});

test('rejects invalid ranges and starts without guaranteed keyframes', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = fakeRenderedSlateVideo(trimRecipeVideo($scratch, 'source', 3.0));
        $output = $scratch->file('trimmed.mp4');
        $unalignedConcat = new VideoConcat(
            [
                fakeRenderedSlateVideo(trimRecipeVideo($scratch, 'first', 1.5)),
                fakeRenderedSlateVideo(trimRecipeVideo($scratch, 'second', 1.5)),
            ],
            VideoConcatMode::Copy,
            null,
            0.0,
            false,
            QualityTier::Delivery,
            24,
            $scratch->file('copied.mp4'),
        );
        $unknownGridInput = fakeRenderedSlateVideo($unalignedConcat);

        expect(fn (): VideoTrim => new VideoTrim($input, -1, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'cannot be negative')
            ->and(fn (): VideoTrim => new VideoTrim($input, 0, 0, $output))
            ->toThrow(InvalidArgumentException::class, 'at least one frame')
            ->and(fn (): VideoTrim => new VideoTrim($input, 48, 25, $output))
            ->toThrow(InvalidArgumentException::class, 'fit within')
            ->and(fn (): VideoTrim => new VideoTrim($input, 12, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'guaranteed keyframe')
            ->and(fn (): VideoTrim => new VideoTrim($unknownGridInput, 24, 24, $output))
            ->toThrow(InvalidArgumentException::class, 'guaranteed keyframe');
    });
});
