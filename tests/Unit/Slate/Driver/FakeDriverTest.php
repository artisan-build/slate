<?php

use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;

test('records compiled recipes and returns queued pipeline results', function (): void {
    $firstRecipe = new CompiledRecipe(['/runtime/ffmpeg', '-version']);
    $secondRecipe = new CompiledRecipe(['/runtime/ffprobe', '-version']);
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
