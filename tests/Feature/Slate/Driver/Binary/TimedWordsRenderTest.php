<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
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
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use Illuminate\Support\Facades\Process;

function requirePinnedCaptionRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned ffmpeg runtime unavailable; rendered timed-caption lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

function renderCaptionSource(
    ScratchDirectory $scratch,
    RecipeCompiler $compiler,
    Driver $driver,
    string $name,
): SlateVideo {
    $image = $scratch->file($name.'.ppm');
    $audio = $scratch->file($name.'.mp3');
    file_put_contents($image->writablePath(), "P3\n2 2\n255\n0 0 0  0 0 0  0 0 0  0 0 0\n");
    $audioResult = $driver->run($compiler->compile(new AudioSilence(
        $audio,
        1.25,
        AudioSampleRate::Hz44100,
        AudioChannelLayout::Mono,
        AudioFormat::Mp3,
        AudioBitrate::Kbps192,
    )));
    expect($audioResult->successful())->toBeTrue($audioResult->errorOutput);

    return SlateVideo::render(new VideoStillClip(
        $image,
        $audio,
        $scratch->file($name.'.mp4'),
        1536,
        1024,
        24,
        1.25,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Intermediate,
        AudioBitrate::Kbps192,
    ), $compiler, $driver);
}

function captionFrameLuma(ScratchPath $video, float $seconds): float
{
    $result = Process::timeout(30)->run([
        FfmpegLocator::fromSystem()->ffmpegPath(),
        '-hide_banner',
        '-loglevel',
        'info',
        '-ss',
        (string) $seconds,
        '-i',
        $video->readablePath(),
        '-frames:v',
        '1',
        '-vf',
        'signalstats,metadata=print:key=lavfi.signalstats.YAVG',
        '-f',
        'null',
        '-',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    if (preg_match('/lavfi\.signalstats\.YAVG=([0-9.]+)/', $result->errorOutput(), $matches) !== 1) {
        throw new RuntimeException('Pinned ffmpeg did not report frame luminance.');
    }

    return (float) $matches[1];
}

test('renders escaped timed words through every keyed animation phase with a staged custom font', function (): void {
    requirePinnedCaptionRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $source = renderCaptionSource($scratch, $compiler, $driver, 'caption-source');
        $font = $scratch->file('customer-font.ttf');
        copy(FfmpegLocator::fromSystem()->fontPath(), $font->writablePath());
        $operation = new TimedWords(
            $source,
            $font,
            $scratch->file('captioned.mp4'),
            64,
            CaptionColor::WarmCream,
            new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
            [TimedWord::fromValues("Mia's: [50%]\\ready,; %{pts}", 128, 720, 0.1, 0.25, 0.55, 0.85, 0.2)],
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            new CaptionDim(0.6, 0.15),
        );

        $captioned = SlateVideo::render($operation, $compiler, $driver);
        $before = captionFrameLuma($captioned->path, 0.04);
        $entering = captionFrameLuma($captioned->path, 0.15);
        $held = captionFrameLuma($captioned->path, 0.45);
        $dimming = captionFrameLuma($captioned->path, 0.625);
        $exiting = captionFrameLuma($captioned->path, 0.95);
        $after = captionFrameLuma($captioned->path, 1.2);

        expect(abs($before - $after))->toBeLessThan(0.1)
            ->and($entering)->toBeGreaterThan($before + 0.1)
            ->and($held)->toBeGreaterThan($entering + 0.1)
            ->and($dimming)->toBeLessThan($held - 0.1)
            ->and($dimming)->toBeGreaterThan($before + 0.1)
            ->and($exiting)->toBeLessThan($dimming - 0.1)
            ->and($exiting)->toBeGreaterThan($after);
    });
})->group('ffmpeg-render');

test('fails rendering instead of substituting a different font for the staged asset', function (): void {
    requirePinnedCaptionRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $source = renderCaptionSource($scratch, $compiler, $driver, 'invalid-font-source');
        $font = $scratch->file('invalid.ttf');
        file_put_contents($font->writablePath(), 'not a TrueType font');
        $operation = new TimedWords(
            $source,
            $font,
            $scratch->file('invalid-font-output.mp4'),
            64,
            CaptionColor::WarmCream,
            new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
            [TimedWord::fromValues('story', 128, 720, 0.1, 0.25, 0.55, 0.85, 0.2)],
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            new CaptionDim(0.6, 0.15),
        );

        $result = $driver->run($compiler->compile($operation));

        expect($result->failed())->toBeTrue($result->errorOutput);
    });
})->group('ffmpeg-render');
