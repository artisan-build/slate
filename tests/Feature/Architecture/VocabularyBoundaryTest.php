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
        'libmp3lame' => ['Slate/Driver/Binary/CompiledLiteral.php'],
        'libopenh264' => ['Slate/Driver/Binary/H264OutputContract.php'],
        'libsvtav1' => ['Services/FfmpegDiagnostics.php'],
        'libvpx-vp9' => ['Services/FfmpegDiagnostics.php'],
        'mjpeg' => ['Slate/Driver/Binary/CompiledLiteral.php'],
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
        'Services/FfmpegBenchmark.php',
    ]);
});

test('keeps every legacy exception attached to a current filter violation', function (): void {
    $violations = ffmpegFilterFragmentLocations(base_path('app'));

    foreach (legacyFfmpegBoundaryExceptions() as $exception) {
        expect($violations)->toContain($exception);
    }
});

/**
 * Provisioning and diagnostics still name the binary, while FfmpegBenchmark remains the sole exact PR 7
 * filter exception. New binary authority above Slate/Driver changes this map and fails the test.
 */
test('keeps detectable binary references at approved class locations', function (): void {
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

test('recursive binary scan catches locator hardcoded literal and compiled recipe references', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Arguments');

    expect(binaryAuthorityLocations($fixtureDirectory))->toBe([
        'binary string literal' => [
            'Deeply/Nested/CompiledRecipeSmuggling.php',
            'Deeply/Nested/HardcodedBinary.php',
        ],
        'compiled output reference' => [
            'Deeply/Nested/CompiledArgumentSmuggling.php',
            'Deeply/Nested/CompiledFilterGraphSmuggling.php',
        ],
        'compiled recipe reference' => ['Deeply/Nested/CompiledRecipeSmuggling.php'],
        'locator or path accessor' => ['Deeply/Nested/TwoStepArguments.php'],
    ]);
});

test('keeps process execution primitives at approved infrastructure and legacy locations', function (): void {
    expect(processPrimitiveLocations(base_path('app')))->toBe([
        'process class reference' => [
            'Console/Commands/ProvisionFfmpegCommand.php',
            'Console/Commands/VerifyObjectStorageCommand.php',
            'Services/FfmpegBenchmark.php',
        ],
    ]);
});

test('recursive process scan catches every enumerated execution primitive', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Processes');

    expect(processPrimitiveLocations($fixtureDirectory))->toBe([
        'backtick operator' => [
            'Deeply/Nested/Backtick.php',
        ],
        'literal process callable' => [
            'Deeply/Nested/DoubleQuotedLiteralCallable.php',
            'Deeply/Nested/HexLiteralCallable.php',
            'Deeply/Nested/LiteralCallable.php',
            'Deeply/Nested/OctalLiteralCallable.php',
            'Deeply/Nested/QualifiedLiteralCallable.php',
        ],
        'process class reference' => [
            'Deeply/Nested/LaravelProcess.php',
            'Deeply/Nested/SymfonyProcess.php',
        ],
        'process function' => [
            'Deeply/Nested/Exec.php',
            'Deeply/Nested/FullyQualifiedExec.php',
            'Deeply/Nested/Passthru.php',
            'Deeply/Nested/PcntlExec.php',
            'Deeply/Nested/PcntlFork.php',
            'Deeply/Nested/Popen.php',
            'Deeply/Nested/ProcOpen.php',
            'Deeply/Nested/ShellExec.php',
            'Deeply/Nested/System.php',
        ],
    ]);
});

test('keeps known v1 filter fragments below the driver boundary', function (): void {
    expect(ffmpegFilterFragmentLocations(base_path('app'), legacyFfmpegBoundaryExceptions()))->toBe([]);
});

test('recursive filter scan catches a nested rogue fragment', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Filters');

    expect(ffmpegFilterFragmentLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/ConcatFilter.php',
        'Deeply/Nested/IndentedHeredocFilter.php',
        'Deeply/Nested/MovieFilter.php',
        'Deeply/Nested/RogueFilter.php',
    ]);
});

test('keeps drawtext text emission in the one compiler that owns expansion and escaping', function (): void {
    expect(drawtextEmitterLocations(base_path('app/Slate/Driver')))->toBe([
        'Binary/FilterGraphCompiler.php' => 1,
    ]);
});

test('recursive compiler emission scan catches a second drawtext text emitter', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/CompilerFilters');

    expect(drawtextEmitterLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueTextEmitter.php' => 2,
    ]);
});

/**
 * The deployed verifier alone may resolve the default disk so it can test the real application wiring.
 * The allowance is revoked if it stops being that regression probe, and it grants no other storage primitive.
 */
test('permits only default disk resolution in the deployed storage verifier', function (): void {
    expect(storageBoundaryLocations(base_path('app')))->toBe([
        'storage disk' => [
            'Console/Commands/VerifyObjectStorageCommand.php',
        ],
    ]);
});

test('recursive storage scan catches a nested rogue disk construction', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Storage');

    expect(storageBoundaryLocations($fixtureDirectory))->toBe([
        'configured object storage construction' => ['Deeply/Nested/RogueStorageConfiguredFactory.php'],
        'fully qualified S3 client construction' => [
            'Deeply/Nested/RogueStorageFullyQualifiedClient.php',
            'Deeply/Nested/RogueStoragePartiallyQualifiedClient.php',
        ],
        'imported S3 client construction' => ['Deeply/Nested/RogueStorageClient.php'],
        'object storage construction' => ['Deeply/Nested/RogueStorageDirect.php'],
        'storage build' => ['Deeply/Nested/RogueStorageBuild.php'],
        'storage cloud' => ['Deeply/Nested/RogueStorageCloud.php'],
        'storage disk' => ['Deeply/Nested/RogueStorage.php'],
        'storage drive' => ['Deeply/Nested/RogueStorageDrive.php'],
        'storage temporary url' => ['Deeply/Nested/RogueStorageTemporaryUrl.php'],
        'storage url' => ['Deeply/Nested/RogueStorageUrl.php'],
    ]);
});
