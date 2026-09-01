<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FfmpegBenchmark;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrepareFfmpegBenchmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $runId,
        public int $durationSeconds,
        public int $chunkSeconds,
    ) {
        $this->timeout = (int) config('slate.benchmark.job_timeout_seconds');
    }

    public function handle(FfmpegBenchmark $benchmark): void
    {
        $prepared = $benchmark->prepare($this->runId, $this->durationSeconds, $this->chunkSeconds);
        $jobs = [];

        foreach ($prepared['chunks'] as $chunkIndex => $chunkPath) {
            $jobs[] = new ProcessFfmpegBenchmarkChunk($this->runId, $chunkIndex, $chunkPath);
        }

        $jobs[] = new FinalizeFfmpegBenchmark(
            $this->runId,
            count($prepared['chunks']),
            $this->durationSeconds,
            $this->chunkSeconds,
            $prepared['started_at_ms'],
        );

        Log::info('slate.ffmpeg_benchmark.prepared', [
            'run_id' => $this->runId,
            'source_type' => 'synthetic_testsrc2',
            'synthetic_input_does_not_verify_bucket_io' => true,
            'queue_connection' => config('queue.default'),
            'duration_seconds' => $this->durationSeconds,
            'target_chunk_seconds' => $this->chunkSeconds,
            'chunk_count' => count($prepared['chunks']),
            'source_size_bytes' => $prepared['source_size_bytes'],
            'generation_duration_ms' => $prepared['generation_duration_ms'],
            'split_duration_ms' => $prepared['split_duration_ms'],
            'encoder' => 'libopenh264',
            'worker' => $prepared['worker'],
        ]);

        Bus::chain($jobs)->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('slate.ffmpeg_benchmark.failed', [
            'run_id' => $this->runId,
            'stage' => 'prepare',
            'message' => $exception?->getMessage(),
        ]);

        app(FfmpegBenchmark::class)->cleanup($this->runId);
    }
}
