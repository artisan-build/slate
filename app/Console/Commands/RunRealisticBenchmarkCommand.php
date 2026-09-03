<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BenchmarkStrategy;
use App\Services\FfmpegBenchmark;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('slate:benchmark {--duration=} {--segment=} {--strategy= : both, xfade, or hybrid (default both)}')]
#[Description('Run the realistic Slate operation benchmark in the current process.')]
final class RunRealisticBenchmarkCommand extends Command
{
    public function handle(FfmpegBenchmark $benchmark): int
    {
        $durationSeconds = (int) ($this->option('duration') ?: config('slate.benchmark.duration_seconds'));
        $segmentSeconds = (int) ($this->option('segment') ?: config('slate.benchmark.segment_seconds'));
        $strategy = BenchmarkStrategy::fromOptionValue($this->option('strategy'));

        if ($durationSeconds < 1 || $segmentSeconds < 1 || $segmentSeconds > $durationSeconds) {
            $this->components->error('Duration and segment length must be positive, and segment length cannot exceed duration.');

            return self::FAILURE;
        }

        if ($strategy === null) {
            $this->components->error('Strategy must be one of: both, xfade, hybrid.');

            return self::FAILURE;
        }

        $result = $benchmark->run(Str::uuid()->toString(), $durationSeconds, $segmentSeconds, $strategy);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
