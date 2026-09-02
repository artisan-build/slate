<?php

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;

test('records compiled recipes and returns queued pipeline results', function (): void {
    $locator = new FfmpegLocator('Linux', 'arm64', '/runtime');
    $firstRecipe = new CompiledRecipe(
        $locator,
        BinaryExecutable::Ffmpeg,
        [CompiledArgument::option('-version')],
    );
    $secondRecipe = new CompiledRecipe(
        $locator,
        BinaryExecutable::Ffprobe,
        [CompiledArgument::option('-version')],
    );
    $driver = new FakeDriver(
        new DriverResult(output: 'first'),
        new DriverResult(errorOutput: 'second failed', exitCode: 1),
    );

    $firstResult = $driver->run($firstRecipe);
    $secondResult = $driver->run($secondRecipe);

    expect($driver->recipes())->toBe([$firstRecipe, $secondRecipe])
        ->and($firstResult->output)->toBe('first')
        ->and($firstResult->successful())->toBeTrue()
        ->and($secondResult->errorOutput)->toBe('second failed')
        ->and($secondResult->failed())->toBeTrue();
});
