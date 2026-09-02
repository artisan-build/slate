<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FfmpegBenchmark;
use App\Slate\Driver\Binary\H264OutputContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FinalizeFfmpegBenchmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $runId,
        public int $chunkCount,
        public int $durationSeconds,
        public int $chunkSeconds,
        public int $startedAtMs,
    ) {
        $this->timeout = (int) config('slate.benchmark.job_timeout_seconds');
    }

    public function handle(FfmpegBenchmark $benchmark): void
    {
        try {
            $measurements = $benchmark->measurements($this->runId, $this->chunkCount);
            $concatenated = $benchmark->concatenate($this->runId, $this->chunkCount);
            $summary = $benchmark->summarizeMeasurements(
                $measurements,
                $this->startedAtMs,
                (int) round(microtime(true) * 1000),
                (int) config('slate.benchmark.visibility_timeout_seconds'),
            );

            Log::info('slate.ffmpeg_benchmark.summary', [
                'run_id' => $this->runId,
                'source_type' => 'synthetic_testsrc2',
                'synthetic_input_does_not_verify_bucket_io' => true,
                'encoder' => H264OutputContract::encoder(),
                'chunk_count' => $this->chunkCount,
                'target_chunk_seconds' => $this->chunkSeconds,
                'requested_duration_seconds' => $this->durationSeconds,
                ...$summary,
                ...$concatenated,
                'worker' => $benchmark->workerLimits(),
            ]);
        } finally {
            $benchmark->cleanup($this->runId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('slate.ffmpeg_benchmark.failed', [
            'run_id' => $this->runId,
            'stage' => 'finalize',
            'message' => $exception?->getMessage(),
        ]);

        app(FfmpegBenchmark::class)->cleanup($this->runId);
    }
}
