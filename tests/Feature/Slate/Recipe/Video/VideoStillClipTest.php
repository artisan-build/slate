<?php

use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFadeOut;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts the audited still clip vocabulary and exposes a trusted Slate output', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $operation = new VideoStillClip(
            $scratch->file('image.png'),
            $scratch->file('audio.mp3'),
            $scratch->file('clip.mp4'),
            1536,
            1024,
            24,
            12.345678,
            VideoFit::ContainPad,
            VideoColor::Black,
            new KenBurnsMotion(6.0, KenBurnsDirection::In, KenBurnsEase::Sine),
            2,
            QualityTier::Intermediate,
            AudioBitrate::Kbps192,
            new VideoFadeOut(11.345678, 1.0, VideoColor::Black),
        );

        $video = fakeRenderedSlateVideo($operation, 10.123456);

        expect($operation->name()->value)->toBe('video.still_clip')
            ->and($video->path)->toBe($operation->output)
            ->and($video->durationSeconds)->toBe(10.123456);
    });
});

test('does not mint a Slate video when rendering fails', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $image = $scratch->file('image.png');
        $audio = $scratch->file('audio.mp3');
        file_put_contents($image->writablePath(), 'image');
        file_put_contents($audio->writablePath(), 'audio');
        $operation = new VideoStillClip(
            $image, $audio, $scratch->file('clip.mp4'), 1536, 1024, 24, 2.0,
            VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );

        expect(fn (): SlateVideo => SlateVideo::render(
            $operation,
            RecipeCompiler::fromSystem(),
            new FakeDriver(new DriverResult('', 'render failed', 1)),
        ))->toThrow(RuntimeException::class, 'render failed');
    });
});

test('rejects still clip values outside the audited video contract', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $arguments = [
            $scratch->file('image.png'),
            $scratch->file('audio.mp3'),
            $scratch->file('clip.mp4'),
            1536,
            1024,
            24,
            2.0,
            VideoFit::ContainPad,
            VideoColor::Black,
            new StillMotion,
            1,
            QualityTier::Delivery,
            AudioBitrate::Kbps192,
        ];

        expect(fn (): VideoStillClip => new VideoStillClip(...array_replace($arguments, [3 => 1280, 4 => 720])))
            ->toThrow(InvalidArgumentException::class, 'dimensions')
            ->and(fn (): VideoStillClip => new VideoStillClip(...array_replace($arguments, [5 => 30])))
            ->toThrow(InvalidArgumentException::class, '24 fps')
            ->and(fn (): VideoStillClip => new VideoStillClip(...array_replace($arguments, [6 => 0.0000001])))
            ->toThrow(InvalidArgumentException::class, 'duration')
            ->and(fn (): VideoStillClip => new VideoStillClip(...array_replace($arguments, [10 => 3])))
            ->toThrow(InvalidArgumentException::class, 'supersampling')
            ->and(fn (): VideoStillClip => new VideoStillClip(...array_replace($arguments, [12 => AudioBitrate::Kbps128])))
            ->toThrow(InvalidArgumentException::class, '192 kbps');
    });
});

test('rejects motion and fades beyond the audited timeline', function (): void {
    expect(fn (): KenBurnsMotion => new KenBurnsMotion(6.000001, KenBurnsDirection::In, KenBurnsEase::Sine))
        ->toThrow(InvalidArgumentException::class, '6 percent')
        ->and(fn (): VideoFadeOut => new VideoFadeOut(-0.1, 1.0, VideoColor::Black))
        ->toThrow(InvalidArgumentException::class, 'fade start');
});
