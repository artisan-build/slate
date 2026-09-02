<?php

/**
 * FfmpegDiagnostics legitimately names alternate encoders because the doctor inspects `ffmpeg -encoders`
 * output. The primary H.264 and AAC output contract belongs only to the binary driver.
 */
test('keeps documented encoder literals at their approved source locations', function (): void {
    $locations = encoderNameLocations(base_path('app'));

    expect($locations)->toBe([
        'aac' => ['Slate/Driver/Binary/H264OutputContract.php'],
        'libkvazaar' => ['Services/FfmpegDiagnostics.php'],
        'libopenh264' => ['Slate/Driver/Binary/H264OutputContract.php'],
        'libsvtav1' => ['Services/FfmpegDiagnostics.php'],
        'libvpx-vp9' => ['Services/FfmpegDiagnostics.php'],
    ]);
});

test('recursive source scan catches a nested rogue encoder name', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/EncoderBoundary');

    expect(encoderNameLocations($fixtureDirectory))->toBe([
        'libopenh264' => ['Deeply/Nested/RogueEncoder.php'],
    ]);
});

test('keeps the pre-vocabulary benchmark residue in an exact PR 7 exception list', function (): void {
    expect(legacyFfmpegBoundaryExceptions())->toBe([
        'Jobs/FinalizeFfmpegBenchmark.php',
        'Jobs/PrepareFfmpegBenchmark.php',
        'Jobs/ProcessFfmpegBenchmarkChunk.php',
        'Services/FfmpegBenchmark.php',
    ]);
});

test('keeps detectable ffmpeg command construction below the driver boundary', function (): void {
    expect(ffmpegArgumentLocations(base_path('app'), legacyFfmpegBoundaryExceptions()))->toBe([]);
});

test('recursive argv scan catches a nested rogue ffmpeg command', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Arguments');

    expect(ffmpegArgumentLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueArguments.php',
    ]);
});

test('keeps known v1 filter fragments below the driver boundary', function (): void {
    expect(ffmpegFilterFragmentLocations(base_path('app'), legacyFfmpegBoundaryExceptions()))->toBe([]);
});

test('recursive filter scan catches a nested rogue fragment', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Filters');

    expect(ffmpegFilterFragmentLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueFilter.php',
    ]);
});

test('keeps direct S3 disk client and object URL construction inside storage', function (): void {
    expect(storageBoundaryLocations(base_path('app')))->toBe([]);
});

test('recursive storage scan catches a nested rogue disk construction', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Storage');

    expect(storageBoundaryLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueStorage.php',
    ]);
});
