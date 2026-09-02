<?php

use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

test('executes only a compiled recipe and returns its complete result', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: 'probe output', errorOutput: 'probe warning', exitCode: 2),
    ]);
    $recipe = new CompiledRecipe(['/runtime/ffprobe', '-version'], '/tmp/slate-job');

    $result = (new BinaryDriver(45))->run($recipe);

    expect($result->output)->toBe("probe output\n")
        ->and($result->errorOutput)->toBe("probe warning\n")
        ->and($result->exitCode)->toBe(2)
        ->and($result->failed())->toBeTrue();

    Process::assertRan(fn (PendingProcess $process, ProcessResult $processResult): bool => $process->command === ['/runtime/ffprobe', '-version']
        && $process->path === '/tmp/slate-job'
        && $process->timeout === 45);
});

test('binds the driver contract to the binary implementation', function (): void {
    expect(resolve(Driver::class))->toBeInstanceOf(BinaryDriver::class);
});
