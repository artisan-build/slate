<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function transitionRecipeVideo(
    ScratchDirectory $scratch,
    string $name,
    int $width = 1536,
    int $height = 1024,
    float $durationSeconds = 3.0,
): VideoStillClip {
    return new VideoStillClip(
        $scratch->file($name.'.png'),
        $scratch->file($name.'.mp3'),
        $scratch->file($name.'.mp4'),
        $width,
        $height,
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

test('selects an exact tail and head frame range for the transition', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $from = fakeRenderedSlateVideo(transitionRecipeVideo($scratch, 'from'));
        $to = fakeRenderedSlateVideo(transitionRecipeVideo($scratch, 'to'));

        $operation = new VideoTransitionSegment(
            $from,
            $to,
            24,
            QualityTier::Delivery,
            $scratch->file('transition.mp4'),
        );

        expect($operation->name()->value)->toBe('video.transition_segment')
            ->and($operation->framesPerSecond())->toBe(24)
            ->and($operation->durationSeconds())->toBe(1.0)
            ->and($operation->fromStartFrame())->toBe(48)
            ->and($operation->fromStartSeconds())->toBe(2.0);
    });
});

test('rejects mismatched inputs and transition ranges outside either clip', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $from = fakeRenderedSlateVideo(transitionRecipeVideo($scratch, 'from'));
        $to = fakeRenderedSlateVideo(transitionRecipeVideo($scratch, 'to'));
        $portrait = fakeRenderedSlateVideo(transitionRecipeVideo($scratch, 'portrait', 1024, 1536));
        $output = $scratch->file('transition.mp4');

        expect(fn (): VideoTransitionSegment => new VideoTransitionSegment($from, $portrait, 24, QualityTier::Delivery, $output))
            ->toThrow(InvalidArgumentException::class, 'matching dimensions')
            ->and(fn (): VideoTransitionSegment => new VideoTransitionSegment($from, $to, 0, QualityTier::Delivery, $output))
            ->toThrow(InvalidArgumentException::class, 'fit within both inputs')
            ->and(fn (): VideoTransitionSegment => new VideoTransitionSegment($from, $to, 73, QualityTier::Delivery, $output))
            ->toThrow(InvalidArgumentException::class, 'fit within both inputs');
    });
});
