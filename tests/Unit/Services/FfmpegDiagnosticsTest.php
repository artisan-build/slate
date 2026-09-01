<?php

use App\Services\FfmpegDiagnostics;

test('recognizes drawtext and all required font build flags', function (): void {
    $diagnostics = new FfmpegDiagnostics;

    expect($diagnostics->hasDrawtextFilter(ffmpegFixture('ffmpeg-filters.txt')))->toBeTrue()
        ->and($diagnostics->hasRequiredFontBuildFlags(ffmpegFixture('ffmpeg-buildconf.txt')))->toBeTrue();
});

test('rejects incomplete filter and build configuration output', function (): void {
    $diagnostics = new FfmpegDiagnostics;

    expect($diagnostics->hasDrawtextFilter(' ... scale V->V Scale the input video size.'))->toBeFalse()
        ->and($diagnostics->hasRequiredFontBuildFlags('--enable-libfreetype --enable-libharfbuzz'))->toBeFalse();
});

test('reads only registered target encoders from the encoder table', function (): void {
    $diagnostics = new FfmpegDiagnostics;

    $encoders = $diagnostics->registeredTargetEncoders(ffmpegFixture('ffmpeg-encoders.txt'));

    expect($encoders)->toBe(['libopenh264', 'libkvazaar', 'libsvtav1', 'libvpx-vp9']);
});

test('requires drawtext to measurably change frame luminance', function (): void {
    $diagnostics = new FfmpegDiagnostics;
    $blankLuma = $diagnostics->averageLuma(ffmpegFixture('ffmpeg-blank-signalstats.txt'));
    $drawtextLuma = $diagnostics->averageLuma(ffmpegFixture('ffmpeg-drawtext-signalstats.txt'));

    expect($blankLuma)->toBe(16.0)
        ->and($drawtextLuma)->toBe(20.07)
        ->and($diagnostics->pixelsChanged($blankLuma, $drawtextLuma))->toBeTrue()
        ->and($diagnostics->pixelsChanged($blankLuma, 16.0))->toBeFalse()
        ->and($diagnostics->pixelsChanged(null, $drawtextLuma))->toBeFalse();
});
