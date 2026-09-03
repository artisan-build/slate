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

        $video = fakeRenderedSlateVideo($operation, 12.245678);

        expect($operation->name()->value)->toBe('video.still_clip')
            ->and($video->path)->toBe($operation->output)
            ->and($video->durationSeconds)->toBe(12.245678);
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

test('rejects incomplete or contradictory probed media', function (
    array $videoStreamOverrides,
    bool $includeVideo,
    bool $includeAudio,
    string $audioCodec,
    float $probedDuration,
    string $expectedMessage,
): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
        $videoStreamOverrides,
        $includeVideo,
        $includeAudio,
        $audioCodec,
        $probedDuration,
        $expectedMessage,
    ): void {
        $operation = new VideoStillClip(
            $scratch->file('image.png'), $scratch->file('audio.mp3'), $scratch->file('clip.mp4'),
            1536, 1024, 24, 12.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );

        expect(fn (): SlateVideo => fakeRenderedSlateVideo(
            $operation,
            $probedDuration,
            $videoStreamOverrides,
            $includeVideo,
            $includeAudio,
            $audioCodec,
        ))->toThrow(RuntimeException::class, $expectedMessage);
    });
})->with([
    'wrong video codec' => [
        ['codec_name' => 'vp9'], true, true, 'aac', 12.0,
        'video.codec_name expected "h264", probed "vp9"',
    ],
    'wrong pixel format' => [
        ['pix_fmt' => 'yuv444p'], true, true, 'aac', 12.0,
        'video.pix_fmt expected "yuv420p", probed "yuv444p"',
    ],
    'full color range' => [
        ['color_range' => 'pc'], true, true, 'aac', 12.0,
        'video.color_range expected "tv", probed "pc"',
    ],
    'unknown color range' => [
        ['color_range' => null], true, true, 'aac', 12.0,
        'video.color_range expected "tv", probed null',
    ],
    'wrong width' => [
        ['width' => 640], true, true, 'aac', 12.0,
        'video.width expected 1536, probed 640',
    ],
    'wrong height' => [
        ['height' => 720], true, true, 'aac', 12.0,
        'video.height expected 1024, probed 720',
    ],
    'wrong frame rate' => [
        ['avg_frame_rate' => '25/1'], true, true, 'aac', 12.0,
        'video.frames_per_second expected 24, probed 25 (avg_frame_rate "25/1")',
    ],
    'fractional frame count' => [
        ['nb_frames' => '288.5'], true, true, 'aac', 12.0,
        'video.nb_frames expected a positive integer, probed "288.5"',
    ],
    'missing video stream' => [
        [], false, true, 'aac', 12.0,
        'Slate video output probe did not contain a video stream.',
    ],
    'missing audio stream' => [
        [], true, false, 'aac', 12.0,
        'audio.codec_name expected to include "aac", probed []',
    ],
    'wrong audio codec' => [
        [], true, true, 'mp3', 12.0,
        'audio.codec_name expected to include "aac", probed ["mp3"]',
    ],
    'non-positive duration' => [
        [], true, true, 'aac', 0.0,
        'format.duration expected a positive microsecond value, probed 0.0',
    ],
    'truncated duration' => [
        [], true, true, 'aac', 10.0,
        'format.duration expected 12.0 +/- 0.25 seconds, probed 10.0 seconds',
    ],
]);

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
