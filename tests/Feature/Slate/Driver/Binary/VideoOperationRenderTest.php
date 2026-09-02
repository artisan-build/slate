<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFadeOut;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoThumbnail;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;

function requirePinnedVideoRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned ffmpeg runtime unavailable; rendered video operation lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

function runPinnedVideoOperation(Operation $operation, RecipeCompiler $compiler, Driver $driver): DriverResult
{
    $result = $driver->run($compiler->compile($operation));

    expect($result->successful())->toBeTrue($result->errorOutput);

    return $result;
}

/** @return array<string, mixed> */
function probeRenderedVideo(ScratchPath $input, RecipeCompiler $compiler, Driver $driver): array
{
    $result = runPinnedVideoOperation(new Probe($input), $compiler, $driver);
    $properties = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($properties)) {
        throw new RuntimeException('Pinned ffprobe did not report video properties.');
    }

    return $properties;
}

function writePpmImage(ScratchPath $path, string $pixels): void
{
    file_put_contents($path->writablePath(), "P3\n2 2\n255\n{$pixels}\n");
}

test('renders every video operation and emitted filter through the pinned ffmpeg binary', function (): void {
    requirePinnedVideoRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $image = $scratch->file('image.ppm');
        $badge = $scratch->file('badge.ppm');
        $audio = $scratch->file('audio.mp3');
        writePpmImage($image, '255 0 0  0 255 0  0 0 255  255 255 0');
        writePpmImage($badge, '255 255 255  255 255 255  255 255 255  255 255 255');
        runPinnedVideoOperation(new AudioSilence(
            $audio,
            1.0,
            AudioSampleRate::Hz44100,
            AudioChannelLayout::Mono,
            AudioFormat::Mp3,
            AudioBitrate::Kbps192,
        ), $compiler, $driver);

        $first = new VideoStillClip(
            $image, $audio, $scratch->file('first.mp4'), 1536, 1024, 24, 1.0,
            VideoFit::ContainPad, VideoColor::Black,
            new KenBurnsMotion(6.0, KenBurnsDirection::In, KenBurnsEase::Sine), 1,
            QualityTier::Intermediate, AudioBitrate::Kbps192,
            new VideoFadeOut(0.75, 0.25, VideoColor::Black),
        );
        $second = new VideoStillClip(
            $image, $audio, $scratch->file('second.mp4'), 1536, 1024, 24, 1.0,
            VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Intermediate, AudioBitrate::Kbps192,
        );
        runPinnedVideoOperation($first, $compiler, $driver);
        runPinnedVideoOperation($second, $compiler, $driver);

        $overlay = new OverlayImage(
            $first->video(), $badge, $scratch->file('overlay.mp4'), 20, 40,
            new OverlayFadeIn(0.25, 0.25), 0.25,
        );
        runPinnedVideoOperation($overlay, $compiler, $driver);

        $crossfaded = new VideoConcat(
            [$overlay->video(), $second->video()], VideoConcatMode::Xfade,
            VideoTransition::Fade, 0.25, true, QualityTier::Delivery, 24,
            $scratch->file('crossfaded.mp4'),
        );
        $hardCutAudio = new VideoConcat(
            [$first->video(), $second->video()], VideoConcatMode::Xfade,
            VideoTransition::Fade, 0.25, false, QualityTier::Delivery, 24,
            $scratch->file('hard-cut-audio.mp4'),
        );
        $copied = new VideoConcat(
            [$first->video(), $second->video()], VideoConcatMode::Copy,
            null, 0.0, false, QualityTier::Intermediate, 24,
            $scratch->file('copied.mp4'),
        );
        runPinnedVideoOperation($crossfaded, $compiler, $driver);
        runPinnedVideoOperation($hardCutAudio, $compiler, $driver);
        runPinnedVideoOperation($copied, $compiler, $driver);

        $thumbnail = $scratch->file('poster.jpg');
        runPinnedVideoOperation(new VideoThumbnail($crossfaded->video(), $thumbnail), $compiler, $driver);
        $properties = probeRenderedVideo($crossfaded->video()->path, $compiler, $driver);
        $videoStream = collect(data_get($properties, 'streams', []))->firstWhere('codec_type', 'video');

        expect($videoStream)->toBeArray()
            ->and(data_get($videoStream, 'codec_name'))->toBe('h264')
            ->and(data_get($videoStream, 'width'))->toBe(1536)
            ->and(data_get($videoStream, 'height'))->toBe(1024)
            ->and(data_get($videoStream, 'pix_fmt'))->toBe('yuv420p')
            ->and(data_get($videoStream, 'color_range'))->toBe('tv')
            ->and($thumbnail->readablePath())->toEndWith('poster.jpg')
            ->and($hardCutAudio->video()->path->readablePath())->toEndWith('hard-cut-audio.mp4')
            ->and($copied->video()->path->readablePath())->toEndWith('copied.mp4');
    });
})->group('ffmpeg-render');
