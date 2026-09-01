<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FfmpegBenchmark;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessFfmpegBenchmarkChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $runId,
        public int $chunkIndex,
        public string $inputPath,
    ) {
        $this->timeout = (int) config('slate.benchmark.job_timeout_seconds');
    }

    public function handle(FfmpegBenchmark $benchmark): void
    {
        $measurement = $benchmark->processChunk($this->runId, $this->chunkIndex, $this->inputPath);

        Log::info('slate.ffmpeg_benchmark.chunk', [
            'run_id' => $this->runId,
            'encoder' => 'libopenh264',
            ...$measurement,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('slate.ffmpeg_benchmark.failed', [
            'run_id' => $this->runId,
            'stage' => 'chunk',
            'chunk_index' => $this->chunkIndex,
            'message' => $exception?->getMessage(),
        ]);

        app(FfmpegBenchmark::class)->cleanup($this->runId);
    }
}
