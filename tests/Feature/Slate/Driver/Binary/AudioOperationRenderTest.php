<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
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
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;

function requirePinnedAudioRuntime(): void
{
    if (FfmpegLocator::fromSystem()->isProvisioned()) {
        return;
    }

    $message = 'Pinned ffmpeg runtime unavailable; rendered audio operation lane not run.';

    if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
        test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
    }

    test()->markTestSkipped($message);
}

function runPinnedOperation(Operation $operation, RecipeCompiler $compiler, Driver $driver): DriverResult
{
    $result = $driver->run($compiler->compile($operation));

    expect($result->successful())->toBeTrue($result->errorOutput);

    return $result;
}

function renderedSilence(ScratchPath $output, float $durationSeconds): AudioSilence
{
    return new AudioSilence(
        $output,
        $durationSeconds,
        AudioSampleRate::Hz44100,
        AudioChannelLayout::Mono,
        AudioFormat::Mp3,
        AudioBitrate::Kbps128,
    );
}

function probeRenderedDuration(ScratchPath $input, RecipeCompiler $compiler, Driver $driver): string
{
    $result = runPinnedOperation(new Probe($input), $compiler, $driver);
    $properties = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
    $duration = is_array($properties) ? data_get($properties, 'format.duration') : null;

    if (! is_string($duration)) {
        throw new RuntimeException('Pinned ffprobe did not report a string format duration.');
    }

    return $duration;
}

test('probes media properties through the pinned ffprobe binary', function (): void {
    requirePinnedAudioRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $input = $scratch->file('probe-input.mp3');
        runPinnedOperation(renderedSilence($input, 1.0), $compiler, $driver);

        $duration = probeRenderedDuration($input, $compiler, $driver);

        expect($duration)->toBe('1.000000');
    });
})->group('ffmpeg-render');

test('renders exact silence duration through the pinned ffmpeg binary', function (): void {
    requirePinnedAudioRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $output = $scratch->file('silence.mp3');

        runPinnedOperation(renderedSilence($output, 1.25), $compiler, $driver);

        expect(probeRenderedDuration($output, $compiler, $driver))->toBe('1.250000');
    });
})->group('ffmpeg-render');

test('renders lead and trail padding through the pinned ffmpeg binary', function (): void {
    requirePinnedAudioRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $input = $scratch->file('pad-input.mp3');
        $output = $scratch->file('padded.mp3');
        runPinnedOperation(renderedSilence($input, 2.0), $compiler, $driver);

        runPinnedOperation(new AudioPad($input, $output, 0.35, 1.25, AudioBitrate::Kbps128), $compiler, $driver);

        expect(probeRenderedDuration($output, $compiler, $driver))->toBe('3.600000');
    });
})->group('ffmpeg-render');

test('renders reencode and copy concat modes through the pinned ffmpeg binary', function (): void {
    requirePinnedAudioRuntime();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = resolve(RecipeCompiler::class);
        $driver = resolve(Driver::class);
        $first = $scratch->file('first.mp3');
        $second = $scratch->file('second.mp3');
        $reencoded = $scratch->file('reencoded.mp3');
        $copied = $scratch->file('copied.mp3');
        runPinnedOperation(renderedSilence($first, 1.0), $compiler, $driver);
        runPinnedOperation(renderedSilence($second, 1.0), $compiler, $driver);

        runPinnedOperation(new AudioConcat(
            [new AudioConcatInput($first), new AudioConcatSilence(0.5), new AudioConcatInput($second)],
            AudioConcatMode::Reencode,
            AudioBitrate::Kbps128,
            $reencoded,
        ), $compiler, $driver);
        runPinnedOperation(new AudioConcat(
            [new AudioConcatInput($first), new AudioConcatInput($second)],
            AudioConcatMode::Copy,
            AudioBitrate::Kbps128,
            $copied,
        ), $compiler, $driver);

        $copyDuration = probeRenderedDuration($copied, $compiler, $driver);

        expect(probeRenderedDuration($reencoded, $compiler, $driver))->toBe('2.500000')
            ->and((float) $copyDuration)->toBeGreaterThan(0.0);
    });
})->group('ffmpeg-render');
