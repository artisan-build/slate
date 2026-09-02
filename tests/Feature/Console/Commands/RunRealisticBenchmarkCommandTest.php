<?php

use App\Services\FfmpegBenchmark;

test('runs the realistic benchmark in the current process', function (): void {
    $result = [
        'run_id' => 'measured-run',
        'summary' => ['segment_realtime_factor' => 0.392],
    ];
    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldReceive('run')
        ->once()
        ->withArgs(fn (string $runId, int $duration, int $segment): bool => $runId !== ''
            && $duration === 540
            && $segment === 40)
        ->andReturn($result);
    $this->instance(FfmpegBenchmark::class, $benchmark);

    $this->artisan('slate:benchmark')
        ->expectsOutput(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))
        ->assertSuccessful();
});

test('rejects an invalid segment length without running the benchmark', function (): void {
    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldNotReceive('run');
    $this->instance(FfmpegBenchmark::class, $benchmark);

    $this->artisan('slate:benchmark', [
        '--duration' => 10,
        '--segment' => 20,
    ])->assertFailed();
});
