<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts a fading image overlay on a typed Slate output', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('image.png'),
            $scratch->file('audio.mp3'),
            $scratch->file('clip.mp4'),
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
        );
        $operation = new OverlayImage(
            fakeRenderedSlateVideo($source),
            $scratch->file('badge.png'),
            $scratch->file('badged.mp4'),
            20,
            40,
            new OverlayFadeIn(1.0, 0.5),
            1.0,
        );

        $video = fakeRenderedSlateVideo($operation);

        expect($operation->name()->value)->toBe('overlay.image')
            ->and($video->durationSeconds)->toBe(3.0)
            ->and($video->path)->toBe($operation->output);
    });
});

test('rejects overlay coordinates and timings outside the source video', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('image.png'), $scratch->file('audio.mp3'), $scratch->file('clip.mp4'),
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );

        $video = fakeRenderedSlateVideo($source);

        expect(fn (): OverlayImage => new OverlayImage(
            $video, $scratch->file('badge.png'), $scratch->file('out.mp4'),
            -1, 0, new OverlayFadeIn(1.0, 0.5), 1.0,
        ))->toThrow(InvalidArgumentException::class, 'coordinates')
            ->and(fn (): OverlayImage => new OverlayImage(
                $video, $scratch->file('badge.png'), $scratch->file('out.mp4'),
                0, 0, new OverlayFadeIn(1.75, 0.5), 1.0,
            ))->toThrow(InvalidArgumentException::class, 'fade must end')
            ->and(fn (): OverlayImage => new OverlayImage(
                $video, $scratch->file('badge.png'), $scratch->file('out.mp4'),
                0, 0, new OverlayFadeIn(1.0, 0.5), 2.000001,
            ))->toThrow(InvalidArgumentException::class, 'enable time');
    });
});
