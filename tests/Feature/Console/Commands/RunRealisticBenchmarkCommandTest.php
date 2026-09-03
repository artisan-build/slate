<?php

use App\Services\BenchmarkStrategy;
use App\Services\FfmpegBenchmark;

test('runs the realistic benchmark in the current process with both strategies', function (): void {
    $result = [
        'run_id' => 'measured-run',
        'summary' => ['peak_operation_ms' => 90000],
    ];
    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldReceive('run')
        ->once()
        ->withArgs(fn (string $runId, int $duration, int $segment, BenchmarkStrategy $strategy): bool => $runId !== ''
            && $duration === 540
            && $segment === 40
            && $strategy === BenchmarkStrategy::Both)
        ->andReturn($result);
    $this->instance(FfmpegBenchmark::class, $benchmark);

    $this->artisan('slate:benchmark')
        ->expectsOutput(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))
        ->assertSuccessful();
});

test('runs a single requested strategy for capability probes', function (): void {
    $result = ['run_id' => 'hybrid-only', 'strategies' => []];
    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldReceive('run')
        ->once()
        ->withArgs(fn (string $runId, int $duration, int $segment, BenchmarkStrategy $strategy): bool => $strategy === BenchmarkStrategy::Hybrid)
        ->andReturn($result);
    $this->instance(FfmpegBenchmark::class, $benchmark);

    $this->artisan('slate:benchmark', ['--strategy' => 'hybrid'])
        ->expectsOutput(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))
        ->assertSuccessful();
});

test('rejects an invalid segment length or strategy without running the benchmark', function (): void {
    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldNotReceive('run');
    $this->instance(FfmpegBenchmark::class, $benchmark);

    $this->artisan('slate:benchmark', [
        '--duration' => 10,
        '--segment' => 20,
    ])->assertFailed();

    $this->artisan('slate:benchmark', [
        '--strategy' => 'warp',
    ])->assertFailed();
});
