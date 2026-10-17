<?php

use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\FfmpegLocator;
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
    $recipe = new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffprobe,
        [CompiledArgument::option(CompiledOption::Version)],
        '/tmp/slate-job',
    );

    $result = (new BinaryDriver(45))->run($recipe);

    expect($result->output)->toBe("probe output\n")
        ->and($result->errorOutput)->toBe("probe warning\n")
        ->and($result->exitCode)->toBe(2)
        ->and($result->failed())->toBeTrue();

    Process::assertRan(fn (PendingProcess $process, ProcessResult $processResult): bool => $process->command === ['/runtime/ffmpeg/bin/ffprobe', '-version']
        && $process->path === '/tmp/slate-job'
        && $process->timeout === 45
        && $process->environment === [
            'FONTCONFIG_FILE' => resource_path('fontconfig/fonts.conf'),
            'FONTCONFIG_PATH' => resource_path('fontconfig'),
        ]);
});

test('requires a timeout of at least one second', function (int $timeoutSeconds, bool $valid): void {
    $driver = fn (): BinaryDriver => new BinaryDriver($timeoutSeconds);

    if ($valid) {
        expect($driver())->toBeInstanceOf(BinaryDriver::class);
    } else {
        expect($driver)->toThrow(InvalidArgumentException::class, 'at least one second');
    }
})->with([
    'zero timeout' => [0, false],
    'negative timeout' => [-5, false],
    'one second is the floor' => [1, true],
]);

test('binds the driver contract to the binary implementation', function (): void {
    expect(resolve(Driver::class))->toBeInstanceOf(BinaryDriver::class);
});

test('fontconfig control declares no system font directories or includes', function (): void {
    $document = new DOMDocument;

    expect($document->load(resource_path('fontconfig/fonts.conf')))->toBeTrue()
        ->and($document->documentElement?->tagName)->toBe('fontconfig')
        ->and($document->getElementsByTagName('dir')->length)->toBe(0)
        ->and($document->getElementsByTagName('include')->length)->toBe(0);
});
