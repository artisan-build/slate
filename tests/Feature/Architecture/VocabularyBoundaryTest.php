<?php

use App\Jobs\FinalizeFanoutRun;
use App\Jobs\RenderFanoutOperation;
use App\Slate\Driver\Binary\CompiledDrawtextOptions;
use App\Slate\Fanout\BucketOperation;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ObjectKey;
use Tests\Fixtures\Boundary\Queue\Deeply\Nested\RogueCompiledCommandJob;
use Tests\Fixtures\Boundary\Queue\Deeply\Nested\RogueLocalPathJob;

function fanoutBoundaryRun(): FanoutRun
{
    $id = FanoutRunId::fromString('55555555-5555-4555-8555-555555555555');
    $prefix = $id->prefix();
    $first = new StoredVideo(
        ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0000.mp4'),
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );
    $second = new StoredVideo(
        ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0001.mp4'),
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );

    return new FanoutRun($id, [$first, $second], [
        new BucketVideoTrim(
            0,
            $first,
            0,
            48,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4'),
        ),
        new BucketVideoTransitionSegment(
            1,
            $first,
            $second,
            24,
            QualityTier::Delivery,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0001.mp4'),
        ),
        new BucketVideoTrim(
            2,
            $second,
            24,
            48,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0002.mp4'),
        ),
    ]);
}

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
        'pcm_s16le' => ['Slate/Driver/Binary/CompiledLiteral.php'],
    ]);
});

test('recursive source scan catches a nested rogue encoder name', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/EncoderBoundary');

    expect(encoderNameLocations($fixtureDirectory))->toBe([
        'libopenh264' => ['Deeply/Nested/RogueEncoder.php'],
    ]);
});

test('keeps the legacy filter exception list empty', function (): void {
    expect(legacyFfmpegBoundaryExceptions())->toBe([]);
});

test('keeps literal visibility window numbers out of application source', function (): void {
    expect(visibilityWindowNumberLocations(base_path('app')))->toBe([]);
});

test('recursive visibility scan catches a nested rogue window number', function (): void {
    expect(visibilityWindowNumberLocations(base_path('tests/Fixtures/Boundary/Policy')))->toBe([
        'Deeply/Nested/RogueVisibilityWindow.php',
    ]);
});

test('keeps bare timeout constants out of queued application jobs', function (): void {
    expect(bareQueuedJobTimeoutLocations(base_path('app/Jobs')))->toBe([]);
});

test('recursive queued job scan catches a nested rogue bare timeout', function (): void {
    expect(bareQueuedJobTimeoutLocations(base_path('tests/Fixtures/Boundary/Queue')))->toBe([
        'Deeply/Nested/RogueBareTimeoutJob.php',
    ]);
});

/**
 * Provisioning and diagnostics still name the binary. New binary authority above Slate/Driver changes
 * this map and fails the test.
 */
test('keeps detectable binary references at approved class locations', function (): void {
    expect(binaryAuthorityLocations(base_path('app')))->toBe([
        'binary string literal' => [
            'Console/Commands/FfmpegDoctorCommand.php',
            'Console/Commands/ProvisionFfmpegCommand.php',
        ],
        'locator or path accessor' => [
            'Console/Commands/FfmpegDoctorCommand.php',
            'Console/Commands/ProvisionFfmpegCommand.php',
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
            'Deeply/Nested/CompiledOutputSmuggling.php',
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
        'Deeply/Nested/AmovieFilter.php',
        'Deeply/Nested/AsetptsFilter.php',
        'Deeply/Nested/AtrimFilter.php',
        'Deeply/Nested/ConcatFilter.php',
        'Deeply/Nested/IndentedHeredocFilter.php',
        'Deeply/Nested/MovieFilter.php',
        'Deeply/Nested/RogueFilter.php',
        'Deeply/Nested/SetptsFilter.php',
        'Deeply/Nested/TrimFilter.php',
    ]);
});

test('keeps input-bearing filter sources out of every driver compiler', function (): void {
    expect(ffmpegDriverInputFilterFragmentLocations(base_path('app/Slate/Driver')))->toBe([]);
});

test('recursive driver filter scan catches every input-bearing filter source', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/Filters');

    expect(ffmpegDriverInputFilterFragmentLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/AmovieFilter.php',
        'Deeply/Nested/ConcatFilter.php',
        'Deeply/Nested/IndentedHeredocFilter.php',
        'Deeply/Nested/MovieFilter.php',
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

test('keeps every expression emitter at its approved compiler location', function (): void {
    expect(drawtextExpressionEmitterLocations(base_path('app/Slate/Driver')))->toBe([
        'Binary/CompiledDrawtextOptions.php' => [
            'alpha' => 1,
            'enable' => 1,
            'x' => 2,
            'y' => 2,
        ],
        'Binary/Operation/OverlayImageCompiler.php' => [
            'alpha' => 1,
            'enable' => 1,
            'x' => 1,
            'y' => 1,
        ],
        'Binary/Operation/VideoStillClipCompiler.php' => [
            'x' => 1,
            'y' => 1,
        ],
    ]);
});

test('recursive expression scan catches caller strings in every evaluated drawtext option', function (): void {
    $fixtureDirectory = base_path('tests/Fixtures/Boundary/CompilerExpressions');

    expect(drawtextExpressionEmitterLocations($fixtureDirectory))->toBe([
        'Deeply/Nested/RogueExpressionEmitter.php' => [
            'alpha' => 1,
            'enable' => 1,
            'x' => 1,
            'y' => 1,
        ],
        'Deeply/Nested/RogueUngatedExpressionEmitter.php' => [
            'alpha' => 1,
            'enable' => 1,
            'x' => 1,
            'y' => 1,
        ],
    ]);
});

test('keeps compiler options and every operation implementation final', function (): void {
    $operationClasses = operationImplementationClasses(base_path('app/Slate/Recipe'));
    $classes = [CompiledDrawtextOptions::class, ...$operationClasses];
    $nonFinalClasses = array_values(array_filter(
        $classes,
        static fn (string $class): bool => ! (new ReflectionClass($class))->isFinal(),
    ));

    expect($operationClasses)->toHaveCount(count(OperationName::cases()))
        ->and($nonFinalClasses)->toBe([]);
});

test('exposes no public string property on any operation implementation', function (): void {
    $publicStringProperties = [];

    foreach (operationImplementationClasses(base_path('app/Slate/Recipe')) as $operationClass) {
        foreach ((new ReflectionClass($operationClass))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getType() instanceof ReflectionNamedType && $property->getType()->getName() === 'string') {
                $publicStringProperties[] = $operationClass.'::$'.$property->getName();
            }
        }
    }

    expect($publicStringProperties)->toBe(allowedOperationPublicStringProperties());
});

test('exposes no public expression-string collection factory', function (): void {
    $compiledOptions = new ReflectionClass(CompiledDrawtextOptions::class);
    $timedFactory = $compiledOptions->getMethod('timed');
    $timedFactoryTypes = array_map(
        static fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
            ? $parameter->getType()->getName()
            : null,
        $timedFactory->getParameters(),
    );

    expect($compiledOptions->getConstructor()?->isPrivate())->toBeTrue();
    expect($timedFactoryTypes)->not->toContain('string');
    expect($timedFactoryTypes)->not->toContain('array');
    expect($timedFactoryTypes)->not->toContain('mixed');
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

test('serialized fan-out jobs contain no scratch objects or recognizable absolute paths', function (): void {
    $run = fanoutBoundaryRun();
    $jobs = [
        ...array_map(
            static fn (BucketOperation $operation): RenderFanoutOperation => new RenderFanoutOperation($run->id, $operation),
            $run->operations,
        ),
        new FinalizeFanoutRun($run, false),
    ];

    foreach ($jobs as $job) {
        expect(queuedJobAbsolutePathViolations($job))->toBe([], $job::class.' carried scratch or an absolute path.');
    }
});

test('recursive queue path guard goes red for a nested absolute-path job', function (): void {
    $violations = queuedJobAbsolutePathViolations(new RogueLocalPathJob('/tmp/host-a/chunk-0001.mp4'));

    if (filter_var(getenv('SLATE_DEMONSTRATE_LOCAL_HANDOFF_GUARD'), FILTER_VALIDATE_BOOL)) {
        expect($violations)->toBe([]);
    }

    expect($violations)->toBe([
        RogueLocalPathJob::class.'::$inputPath contains host-local path /tmp/host-a/chunk-0001.mp4',
    ]);
});

test('serialized fan-out jobs contain no compiler objects or known compiled vocabulary', function (): void {
    $run = fanoutBoundaryRun();
    $jobs = [
        ...array_map(
            static fn (BucketOperation $operation): RenderFanoutOperation => new RenderFanoutOperation($run->id, $operation),
            $run->operations,
        ),
        new FinalizeFanoutRun($run, false),
    ];

    foreach ($jobs as $job) {
        expect(queuedJobKnownCompiledVocabularyViolations($job))->toBe([], $job::class.' carried known compiled vocabulary.');
    }
});

test('recursive known-vocabulary guard goes red for a nested compiled-command job', function (): void {
    $violations = queuedJobKnownCompiledVocabularyViolations(new RogueCompiledCommandJob([
        '/runtime/ffmpeg/bin/ffmpeg',
        '-filter_complex',
        '[0:v][1:v]xfade=transition=fade',
        '-c:v',
        'libopenh264',
    ]));

    if (filter_var(getenv('SLATE_DEMONSTRATE_COMPILED_PAYLOAD_GUARD'), FILTER_VALIDATE_BOOL)) {
        expect($violations)->toBe([]);
    }

    expect($violations)->not->toBe([])
        ->and(implode('; ', $violations))->toContain('binary or encoder vocabulary')
        ->and(implode('; ', $violations))->toContain('compiled filter vocabulary');
});
