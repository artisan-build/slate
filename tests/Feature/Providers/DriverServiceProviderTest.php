<?php

use App\Providers\DriverServiceProvider;
use App\Services\FfmpegDiagnostics;
use App\Slate\Driver\Binary\BenchmarkOutputVerifier;
use App\Slate\Driver\Binary\DiagnosticRecipeCompiler;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;
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

        if ($executable === 'ffmpeg' && ! in_array('-nostdin', $command, true)) {
            $violations[] = "invocation {$index} ({$executable}) lacks -nostdin";
        }

        foreach (compiledInputScopes($command) as $scopeIndex => $inputScope) {
            if (compiledEffectiveOptionValue($inputScope, '-protocol_whitelist') !== 'file') {
                $violations[] = "invocation {$index} ({$executable}) input scope {$scopeIndex} effective -protocol_whitelist is not file";
            }
        }

        if ($executable !== 'ffmpeg') {
            continue;
        }

        $outputScopes = compiledOutputScopes($command);

        if ($outputScopes === []) {
            $violations[] = "invocation {$index} ({$executable}) has no sealed output scope";
        }

        foreach ($outputScopes as $scopeIndex => $outputScope) {
            if (compiledEffectiveOptionValue($outputScope, '-map_metadata') !== '-1') {
                $violations[] = "invocation {$index} ({$executable}) output scope {$scopeIndex} effective -map_metadata is not -1";
            }

            $duration = compiledEffectiveOptionValue($outputScope, '-t');

            if ($duration === null || ! is_numeric($duration) || (float) $duration <= 0 || (float) $duration > 86_400) {
                $violations[] = "invocation {$index} ({$executable}) output scope {$scopeIndex} effective -t exceeds 86400";
            }

            $fileSize = compiledEffectiveOptionValue($outputScope, '-fs');

            if ($fileSize === null || ! ctype_digit($fileSize) || (int) $fileSize < 1 || (int) $fileSize > 17_179_869_184) {
                $violations[] = "invocation {$index} ({$executable}) output scope {$scopeIndex} effective -fs exceeds 17179869184";
            }
        }
    }

    return $violations;
}

/**
 * @param  non-empty-list<string>  $command
 * @return non-empty-list<list<string>>
 */
function compiledInputScopes(array $command): array
{
    $scopes = [];
    $scopeStart = 1;

    foreach ($command as $index => $argument) {
        if ($argument !== '-i') {
            continue;
        }

        $scopes[] = array_slice($command, $scopeStart, $index - $scopeStart);
        $scopeStart = $index + 2;
    }

    return $scopes === [] ? [array_slice($command, 1)] : $scopes;
}

/**
 * @param  non-empty-list<string>  $command
 * @return list<non-empty-list<string>>
 */
function compiledOutputScopes(array $command): array
{
    $prefix = ffmpegOutputHardeningArguments();
    $starts = [];

    foreach (array_keys($command) as $index) {
        if (array_slice($command, $index, count($prefix)) === $prefix) {
            $starts[] = $index;
        }
    }

    $scopes = [];

    foreach ($starts as $scopeIndex => $start) {
        $end = $starts[$scopeIndex + 1] ?? count($command);
        $scopes[] = array_slice($command, $start, $end - $start);
    }

    return $scopes;
}

/** @param list<string> $scope */
function compiledEffectiveOptionValue(array $scope, string $option): ?string
{
    $effectiveValue = null;

    foreach ($scope as $index => $argument) {
        if ($argument === $option && isset($scope[$index + 1])) {
            $effectiveValue = $scope[$index + 1];
        }
    }

    return $effectiveValue;
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
            new AudioConcat(
                [new AudioConcatInput($input)],
                AudioConcatMode::Copy,
                AudioBitrate::Kbps128,
                $scratch->file('concat-copy.mp3'),
            ),
            new AudioPad($input, $scratch->file('padded.mp3'), 0.25, 0.5, AudioBitrate::Kbps128),
            $stillClip,
            $slice,
            $mux,
            VideoClip::coarse(
                $videoOutput,
                100_000,
                1_000_000,
                2.0,
                $scratch->file('coarse.mp4'),
            ),
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
            new VideoConcat(
                [$video, $secondVideo], VideoConcatMode::Xfade,
                VideoTransition::Fade, 0.5, false, QualityTier::Delivery, 24,
                $scratch->file('joined-hard-cut-audio.mp4'),
            ),
            new VideoConcat(
                [$video, $secondVideo], VideoConcatMode::Copy,
                null, 0.0, false, QualityTier::Delivery, 24,
                $scratch->file('joined-copy.mp4'),
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
        $runtimeInput = $scratch->file('runtime-input.mp4');
        file_put_contents($runtimeInput->writablePath(), 'runtime video');
        $runtimeDriver = new FakeDriver(
            new DriverResult('0, 0, 0, 0, 0, d41d8cd98f00b204e9800998ecf8427e'),
            new DriverResult(pack('v', 1)),
            new DriverResult(errorOutput: 'lavfi.signalstats.YAVG=42'),
        );
        $verifier = new BenchmarkOutputVerifier($runtimeDriver, new FfmpegDiagnostics);
        $verifier->frameHashes($runtimeInput);
        $verifier->audioSamples($runtimeInput);
        $verifier->frameLuma($runtimeInput, 0.1);
        array_push(
            $commands,
            ...array_map(
                static fn ($recipe): array => $recipe->command(),
                $runtimeDriver->recipes(),
            ),
        );
        $executables = array_map(static fn (array $command): string => basename($command[0]), $commands);
        $missingControl = [['/runtime/ffmpeg/bin/ffmpeg', '-i', '/tmp/input.mp4', '/tmp/output.mp4']];
        $badFirstSafeLastControl = [[
            '/runtime/ffmpeg/bin/ffmpeg', '-nostdin',
            '-protocol_whitelist', 'http', '-i', '/tmp/source.mp4',
            '-protocol_whitelist', 'file', '-i', '/tmp/slate.mp4',
            '-map_metadata', '-1', '-t', '86400', '-fs', '17179869184',
            '-t', '200000', '-fs', '20000000000', '/tmp/unsafe.mp4',
            '-map_metadata', '-1', '-t', '86400', '-fs', '17179869184', '/tmp/safe.mp4',
        ]];

        expect(app()->getProviders(DriverServiceProvider::class))->toHaveCount(1)
            ->and($compiler)->toBeInstanceOf(RecipeCompiler::class)
            ->and(compiledInvocationHardeningViolations($commands))->toBe([])
            ->and(compiledInvocationHardeningViolations($missingControl))->not->toBe([])
            ->and(compiledInvocationHardeningViolations($badFirstSafeLastControl))->toBe([
                'invocation 0 (ffmpeg) input scope 0 effective -protocol_whitelist is not file',
                'invocation 0 (ffmpeg) output scope 0 effective -t exceeds 86400',
                'invocation 0 (ffmpeg) output scope 0 effective -fs exceeds 17179869184',
            ])
            ->and($executables)->toBe([
                'ffprobe', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
                'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
                'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
                'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg', 'ffmpeg',
            ]);
    });
});
