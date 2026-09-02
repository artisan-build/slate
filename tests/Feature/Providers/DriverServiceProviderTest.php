<?php

use App\Providers\DriverServiceProvider;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioConcat;
use App\Slate\Recipe\Audio\AudioConcatInput;
use App\Slate\Recipe\Audio\AudioConcatMode;
use App\Slate\Recipe\Audio\AudioConcatSilence;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioPad;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoThumbnail;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('registers production compilers for every shipped operation', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp3');
        file_put_contents($input->writablePath(), 'audio');
        $image = $scratch->file('image.png');
        file_put_contents($image->writablePath(), 'image');
        $videoOutput = $scratch->file('video.mp4');
        file_put_contents($videoOutput->writablePath(), 'video');
        $secondVideoOutput = $scratch->file('video-two.mp4');
        file_put_contents($secondVideoOutput->writablePath(), 'video');
        $stillClip = new VideoStillClip(
            $image, $input, $videoOutput, 1536, 1024, 24, 2.0,
            VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $secondStillClip = new VideoStillClip(
            $image, $input, $secondVideoOutput, 1536, 1024, 24, 2.0,
            VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $video = fakeRenderedSlateVideo($stillClip);
        $secondVideo = fakeRenderedSlateVideo($secondStillClip);
        $compiler = resolve(RecipeCompiler::class);
        $operations = [
            new Probe($input),
            new AudioSilence(
                $scratch->file('silence.mp3'),
                1.0,
                AudioSampleRate::Hz44100,
                AudioChannelLayout::Mono,
                AudioFormat::Mp3,
                AudioBitrate::Kbps128,
            ),
            new AudioConcat(
                [new AudioConcatInput($input), new AudioConcatSilence(0.5)],
                AudioConcatMode::Reencode,
                AudioBitrate::Kbps128,
                $scratch->file('concat.mp3'),
            ),
            new AudioPad($input, $scratch->file('padded.mp3'), 0.25, 0.5, AudioBitrate::Kbps128),
            $stillClip,
            new VideoConcat(
                [$video, $secondVideo], VideoConcatMode::Xfade,
                VideoTransition::Fade, 0.5, true, QualityTier::Delivery, 24,
                $scratch->file('joined.mp4'),
            ),
            new OverlayImage(
                $video, $image, $scratch->file('overlay.mp4'), 0, 0,
                new OverlayFadeIn(0.5, 0.5), 0.5,
            ),
            new VideoThumbnail($video, $scratch->file('poster.jpg')),
        ];

        $executables = array_map(
            static fn (Operation $operation): string => basename($compiler->compile($operation)->command()[0]),
            $operations,
        );

        expect(app()->getProviders(DriverServiceProvider::class))->toHaveCount(1)
            ->and($compiler)->toBeInstanceOf(RecipeCompiler::class)
            ->and($executables)->toBe(['ffprobe', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg']);
    });
});
