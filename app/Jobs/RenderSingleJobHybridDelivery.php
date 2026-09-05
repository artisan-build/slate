<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FanoutRunCleanup;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RenderSingleJobHybridDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public FanoutRun $run)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue(
            (string) config('slate.execution.render_queue'),
        );
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(
        ObjectStorage $objectStorage,
        JobScratch $jobScratch,
        RecipeCompiler $compiler,
        Driver $driver,
        HybridDeliveryAssembly $assembly,
        FanoutRunCleanup $cleanup,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();

        if ($objectStorage->exists($this->run->outputKey())
            && $objectStorage->exists($this->run->resultKey())) {
            $cleanup->workObjects($this->run);

            return;
        }

        $startedAtMs = self::monotonicMilliseconds();

        $jobScratch->run(function (ScratchDirectory $scratch) use (
            $objectStorage,
            $compiler,
            $driver,
            $assembly,
            $startedAtMs,
        ): void {
            $inputs = [];

            foreach ($this->run->inputs as $index => $storedInput) {
                $localPath = $scratch->file(sprintf('input-%02d.mp4', $index));
                $objectStorage->stageIn($storedInput->objectKey, $localPath);
                $inputs[] = SlateVideo::fromStored($storedInput, $localPath, $compiler, $driver);
            }

            $transition = null;

            foreach ($this->run->operations as $operation) {
                if ($operation instanceof BucketVideoTransitionSegment) {
                    $transition = $operation;

                    break;
                }
            }

            if ($transition === null) {
                throw new RuntimeException('Single-job hybrid delivery requires a typed transition segment.');
            }

            $hybrid = $assembly->assemble(
                $inputs,
                $transition->frameCount,
                $transition->qualityTier,
                $scratch->file('output.mp4'),
            );
            $measurements = $hybrid['measurements'];
            $concatMeasurement = array_pop($measurements);

            if ($concatMeasurement === null || count($measurements) !== count($this->run->operations)) {
                throw new RuntimeException('Single-job hybrid measurements do not match the queued plan.');
            }

            $hostname = gethostname() ?: 'unknown';
            $operationEvidence = [];

            foreach ($this->run->operations as $index => $operation) {
                $measurement = $measurements[$index];

                if ($measurement['operation'] !== $operation->name()->value) {
                    throw new RuntimeException('Single-job hybrid execution diverged from the queued typed plan.');
                }

                $operationEvidence[] = [
                    'operation_index' => $operation->index(),
                    'operation' => $measurement['operation'],
                    'hostname' => $hostname,
                    'processing_duration_ms' => $measurement['processing_duration_ms'],
                    'output_size_bytes' => $measurement['output_size_bytes'],
                ];
            }

            $output = $hybrid['output'];
            $objectStorage->stageOut($output->path, $this->run->outputKey());
            $result = [
                'status' => 'completed',
                'run_id' => $this->run->id->value(),
                'output_object_key' => $this->run->outputKey()->value(),
                'output' => [
                    'duration_ms' => (int) round($output->durationSeconds * 1000),
                    'frame_count' => $output->frameCount,
                    'width' => $output->width,
                    'height' => $output->height,
                ],
                'operations' => $operationEvidence,
                'worker_hostnames' => [$hostname],
                'distinct_worker_count' => 1,
                'multi_host_observed' => false,
                'job_processing_duration_ms' => self::monotonicMilliseconds() - $startedAtMs,
                'final_concat' => [
                    'operation' => $concatMeasurement['operation'],
                    'mode' => 'copy',
                    'processing_duration_ms' => $concatMeasurement['processing_duration_ms'],
                    'hostname' => $hostname,
                ],
            ];
            $resultPath = $scratch->file('result.json');
            file_put_contents(
                $resultPath->writablePath(),
                json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            $objectStorage->stageOut($resultPath, $this->run->resultKey());
        });

        $cleanup->workObjects($this->run);

        Log::info('slate.hybrid-single-job.completed', [
            'run_id' => $this->run->id->value(),
            'result_object_key' => $this->run->resultKey()->value(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(FanoutRunCleanup::class)->allObjects($this->run);

        Log::error('slate.hybrid-single-job.failed', [
            'run_id' => $this->run->id->value(),
            'message' => $exception?->getMessage(),
        ]);
    }

    private static function monotonicMilliseconds(): int
    {
        return intdiv((int) hrtime(true), 1_000_000);
    }
}
