<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/resources',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/_ide_helper_models.php',
        __DIR__.'/bootstrap/cache',
        __DIR__.'/app/Console/Commands/ProvisionFfmpegCommand.php',
        __DIR__.'/app/Console/Commands/FfmpegDoctorCommand.php',
        __DIR__.'/app/Slate/Driver/Binary/FfmpegLocator.php',
        __DIR__.'/app/Services/FfmpegDiagnostics.php',
        __DIR__.'/app/Services/FfmpegBenchmark.php',
        __DIR__.'/config/slate.php',
        __DIR__.'/config/cache.php',
        __DIR__.'/tests/Fixtures/Boundary/Processes/Deeply/Nested/LiteralCallable.php',
        __DIR__.'/tests/Fixtures/Boundary/Processes/Deeply/Nested/QualifiedLiteralCallable.php',
        __DIR__.'/tests/Fixtures/Boundary/Processes/Deeply/Nested/DoubleQuotedLiteralCallable.php',
        __DIR__.'/tests/Fixtures/Boundary/Processes/Deeply/Nested/HexLiteralCallable.php',
        __DIR__.'/tests/Fixtures/Boundary/Processes/Deeply/Nested/OctalLiteralCallable.php',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withImportNames(false, false, true, true)
    ->withTypeCoverageLevel(1)
    ->withDeadCodeLevel(1)
    ->withCodeQualityLevel(1);
