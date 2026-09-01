<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PrepareFfmpegBenchmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('slate:benchmark-queue {--duration=} {--chunk=}')]
#[Description('Dispatch a synthetic, chunked ffmpeg benchmark to the default queue.')]
final class DispatchFfmpegBenchmarkCommand extends Command
{
    public function handle(): int
    {
        $durationSeconds = (int) ($this->option('duration') ?: config('slate.benchmark.duration_seconds'));
        $chunkSeconds = (int) ($this->option('chunk') ?: config('slate.benchmark.chunk_seconds'));

        if ($durationSeconds < 1 || $chunkSeconds < 1 || $chunkSeconds > $durationSeconds) {
            $this->components->error('Duration and chunk length must be positive, and chunk length cannot exceed duration.');

            return self::FAILURE;
        }

        $runId = Str::uuid()->toString();

        PrepareFfmpegBenchmark::dispatch($runId, $durationSeconds, $chunkSeconds);

        $this->components->info('Queued ffmpeg benchmark dispatched.');
        $this->line('Run ID: '.$runId);
        $this->line('Queue connection: '.config('queue.default'));
        $this->line("Source: synthetic testsrc2, {$durationSeconds}s at 1280x720/30fps");
        $this->line("Target chunk length: {$chunkSeconds}s");

        return self::SUCCESS;
    }
}
