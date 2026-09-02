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

/**
 * Provisioning and diagnostics still name the binary, while the four benchmark files remain the exact
 * PR 7 exception. New binary authority above Slate/Driver changes this map and fails the test.
 */
test('keeps binary path and compiled recipe authority at approved class locations', function (): void {
    expect(binaryAuthorityLocations(base_path('app')))->toBe([
        'binary string literal' => [
            'Console/Commands/DispatchFfmpegBenchmarkCommand.php',
            'Console/Commands/FfmpegDoctorCommand.php',
            'Console/Commands/ProvisionFfmpegCommand.php',
            'Jobs/FinalizeFfmpegBenchmark.php',
            'Jobs/PrepareFfmpegBenchmark.php',
            'Jobs/ProcessFfmpegBenchmarkChunk.php',
            'Services/FfmpegBenchmark.php',
        ],
        'locator or path accessor' => [
            'Console/Commands/FfmpegDoctorCommand.php',
            'Console/Commands/ProvisionFfmpegCommand.php',
            'Services/FfmpegBenchmark.php',
        ],
    ]);
});

test('recursive authority scan catches indirect literal and compiled recipe bypasses', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Arguments');

    expect(binaryAuthorityLocations($fixtureDirectory))->toBe([
        'binary string literal' => [
            'Deeply/Nested/CompiledRecipeSmuggling.php',
            'Deeply/Nested/HardcodedBinary.php',
        ],
        'compiled recipe reference' => ['Deeply/Nested/CompiledRecipeSmuggling.php'],
        'locator or path accessor' => ['Deeply/Nested/TwoStepArguments.php'],
    ]);
});

test('keeps known v1 filter fragments below the driver boundary', function (): void {
    expect(ffmpegFilterFragmentLocations(base_path('app'), legacyFfmpegBoundaryExceptions()))->toBe([]);
});

test('recursive filter scan catches a nested rogue fragment', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Filters');

    expect(ffmpegFilterFragmentLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/ConcatFilter.php',
        'Deeply/Nested/MovieFilter.php',
        'Deeply/Nested/RogueFilter.php',
    ]);
});

test('keeps drawtext text emission in the one compiler that owns expansion and escaping', function (): void {
    expect(drawtextEmitterLocations(base_path('app/Slate/Driver')))->toBe([
        'Binary/FilterGraphCompiler.php',
    ]);
});

test('recursive compiler emission scan catches a second drawtext text emitter', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/CompilerFilters');

    expect(drawtextEmitterLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueTextEmitter.php',
    ]);
});

test('keeps direct S3 disk client and object URL construction inside storage', function (): void {
    expect(storageBoundaryLocations(base_path('app')))->toBe([]);
});

test('recursive storage scan catches a nested rogue disk construction', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Storage');

    expect(storageBoundaryLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueStorage.php',
        'Deeply/Nested/RogueStorageBuild.php',
        'Deeply/Nested/RogueStorageClient.php',
        'Deeply/Nested/RogueStorageCloud.php',
        'Deeply/Nested/RogueStorageConfiguredFactory.php',
        'Deeply/Nested/RogueStorageDirect.php',
        'Deeply/Nested/RogueStorageDrive.php',
        'Deeply/Nested/RogueStorageFullyQualifiedClient.php',
        'Deeply/Nested/RogueStorageTemporaryUrl.php',
        'Deeply/Nested/RogueStorageUrl.php',
    ]);
});
