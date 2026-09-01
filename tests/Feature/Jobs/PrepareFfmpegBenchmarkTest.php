<?php

use App\Jobs\FinalizeFfmpegBenchmark;
use App\Jobs\PrepareFfmpegBenchmark;
use App\Jobs\ProcessFfmpegBenchmarkChunk;
use App\Services\FfmpegBenchmark;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

test('dispatches one chained job per prepared chunk followed by finalization', function (): void {
    Bus::fake();
    Log::spy();

    $benchmark = Mockery::mock(FfmpegBenchmark::class);
    $benchmark->shouldReceive('prepare')
        ->once()
        ->with('run-123', 30, 10)
        ->andReturn([
            'chunks' => ['/scratch/chunk-000.mp4', '/scratch/chunk-001.mp4', '/scratch/chunk-002.mp4'],
            'source_size_bytes' => 1000,
            'generation_duration_ms' => 100,
            'split_duration_ms' => 20,
            'started_at_ms' => 1000,
            'worker' => [
                'hostname' => 'worker-a',
                'memory_max' => '2147483648',
                'memory_bytes' => 2147483648,
                'cpu_max' => '100000 100000',
                'cpu_cores' => 1.0,
            ],
        ]);

    (new PrepareFfmpegBenchmark('run-123', 30, 10))->handle($benchmark);

    Bus::assertChained([
        ProcessFfmpegBenchmarkChunk::class,
        ProcessFfmpegBenchmarkChunk::class,
        ProcessFfmpegBenchmarkChunk::class,
        FinalizeFfmpegBenchmark::class,
    ]);
});
