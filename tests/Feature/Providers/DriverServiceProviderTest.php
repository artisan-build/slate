<?php

use App\Providers\DriverServiceProvider;
use App\Slate\Driver\Binary\DiagnosticRecipeCompiler;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
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
use App\Slate\Recipe\Caption\CaptionColor;
use App\Slate\Recipe\Caption\CaptionDim;
use App\Slate\Recipe\Caption\CaptionEntryEase;
use App\Slate\Recipe\Caption\CaptionExitEase;
use App\Slate\Recipe\Caption\CaptionShadow;
use App\Slate\Recipe\Caption\CaptionShadowColor;
use App\Slate\Recipe\Caption\TimedWord;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoAudioMux;
use App\Slate\Recipe\Video\VideoClip;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoStillClipSlice;
use App\Slate\Recipe\Video\VideoThumbnail;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

/**
 * @param  list<non-empty-list<string>>  $commands
 * @return list<string>
 */
function compiledInvocationHardeningViolations(array $commands): array
{
    $violations = [];

    foreach ($commands as $index => $command) {
        $executable = basename($command[0]);
        $required = $executable === 'ffmpeg'
            ? ['-nostdin' => null, '-protocol_whitelist' => 'file', '-map_metadata' => '-1', '-t' => null, '-fs' => null]
            : ['-protocol_whitelist' => 'file'];

        foreach ($required as $option => $expectedValue) {
            $optionIndex = array_search($option, $command, true);

            if ($optionIndex === false
                || ($expectedValue !== null && ($command[$optionIndex + 1] ?? null) !== $expectedValue)) {
                $violations[] = "invocation {$index} ({$executable}) lacks {$option}"
                    .($expectedValue === null ? '' : " {$expectedValue}");
            }
        }
    }

    return $violations;
}

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
        $slice = new VideoStillClipSlice(
            $image,
            $scratch->file('silent-slice.mp4'),
            1536,
            1024,
            24,
            1_000_000,
            VideoFit::ContainPad,
            VideoColor::Black,
            1,
            QualityTier::Delivery,
        );
        $silentSlice = fakeRenderedSlateVideo($slice);
        $mux = new VideoAudioMux(
            $silentSlice,
            $input,
            AudioBitrate::Kbps192,
            $scratch->file('muxed.mp4'),
        );
        $font = $scratch->file('font.ttf');
        file_put_contents($font->writablePath(), "\x00\x01\x00\x00");
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
            $slice,
            $mux,
            VideoClip::precise($videoOutput, 0, 1_000_000, $scratch->file('clipped.mp4')),
            new VideoTrim($video, 24, 24, $scratch->file('trimmed.mp4')),
            new VideoTransitionSegment(
                $video, $secondVideo, 24, QualityTier::Delivery, $scratch->file('transition.mp4'),
            ),
            new VideoConcat(
                [$video, $secondVideo], VideoConcatMode::Xfade,
                VideoTransition::Fade, 0.5, true, QualityTier::Delivery, 24,
                $scratch->file('joined.mp4'),
            ),
            new OverlayImage(
                $video, $image, $scratch->file('overlay.mp4'), 0, 0,
                new OverlayFadeIn(0.5, 0.5), 0.5,
            ),
            new TimedWords(
                $video, $font, $scratch->file('captioned.mp4'), 64, CaptionColor::WarmCream,
                new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
                [TimedWord::fromValues('story', 128, 720, 0.1, 0.2, 0.5, 0.8, 0.2)],
                CaptionEntryEase::OutBack, CaptionExitEase::InOutQuad, new CaptionDim(0.6, 0.1),
            ),
            new VideoThumbnail($video, $scratch->file('poster.jpg')),
        ];

        $commands = array_map(
            static fn (Operation $operation): array => $compiler->compile($operation)->command(),
            $operations,
        );
        $diagnostics = new DiagnosticRecipeCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        );
        array_push(
            $commands,
            $diagnostics->version()->command(),
            $diagnostics->filters()->command(),
            $diagnostics->buildConfiguration()->command(),
            $diagnostics->encoders()->command(),
            $diagnostics->luma(null)->command(),
        );
        $executables = array_map(static fn (array $command): string => basename($command[0]), $commands);
        $positiveControl = [['/runtime/ffmpeg/bin/ffmpeg', '-i', '/tmp/input.mp4', '/tmp/output.mp4']];

        expect(app()->getProviders(DriverServiceProvider::class))->toHaveCount(1)
            ->and($compiler)->toBeInstanceOf(RecipeCompiler::class)
            ->and(compiledInvocationHardeningViolations($commands))->toBe([])
            ->and(compiledInvocationHardeningViolations($positiveControl))->not->toBe([])
            ->and($executables)->toBe([
                'ffprobe', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
                'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
                'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
            ]);
    });
});
