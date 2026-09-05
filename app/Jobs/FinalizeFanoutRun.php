<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FanoutRunCleanup;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Fanout\FanoutReceipt;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class FinalizeFanoutRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public FanoutRun $run,
        public bool $batchFailed,
    ) {
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
        FanoutRunCleanup $cleanup,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();
        $startedAtMs = self::monotonicMilliseconds();

        if ($this->batchFailed) {
            $cleanup->allObjects($this->run);

            Log::warning('slate.fanout.failed', [
                'run_id' => $this->run->id->value(),
                'stage' => 'operation_batch',
            ]);

            return;
        }

        if ($objectStorage->exists($this->run->outputKey())
            && $objectStorage->exists($this->run->resultKey())) {
            $cleanup->workObjects($this->run);

            return;
        }

        $jobScratch->run(function (ScratchDirectory $scratch) use (
            $objectStorage,
            $compiler,
            $driver,
            $startedAtMs,
        ): void {
            $segments = [];
            $receipts = [];

            foreach ($this->run->operations as $operation) {
                $segmentPath = $scratch->file(sprintf('segment-%04d.mp4', $operation->index()));
                $storedOutput = $operation->output();
                $objectStorage->stageIn($storedOutput->objectKey, $segmentPath);
                $segments[] = SlateVideo::fromStored($storedOutput, $segmentPath, $compiler, $driver);

                $receiptPath = $scratch->file(sprintf('receipt-%04d.json', $operation->index()));
                $objectStorage->stageIn($this->run->receiptKey($operation->index()), $receiptPath);
                $receiptJson = file_get_contents($receiptPath->readablePath());

                if (! is_string($receiptJson)) {
                    throw new RuntimeException('Unable to read staged fan-out receipt.');
                }

                $receipts[] = FanoutReceipt::fromJson($receiptJson, $operation);
            }

            $firstSegment = $segments[0];
            $concatStartedAtMs = self::monotonicMilliseconds();
            $output = SlateVideo::render(new VideoConcat(
                $segments,
                VideoConcatMode::Copy,
                null,
                0.0,
                false,
                $firstSegment->qualityTier,
                $firstSegment->framesPerSecond,
                $scratch->file('output.mp4'),
            ), $compiler, $driver);
            $concatProcessingMs = self::monotonicMilliseconds() - $concatStartedAtMs;
            $objectStorage->stageOut($output->path, $this->run->outputKey());
            $operationEvidence = array_map(
                static fn (FanoutReceipt $receipt): array => $receipt->toArray(),
                $receipts,
            );
            $workerHostnames = array_values(array_unique(array_column($operationEvidence, 'hostname')));
            sort($workerHostnames);
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
                'worker_hostnames' => $workerHostnames,
                'distinct_worker_count' => count($workerHostnames),
                'multi_host_observed' => count($workerHostnames) > 1,
                'job_processing_duration_ms' => self::monotonicMilliseconds() - $startedAtMs,
                'final_concat' => [
                    'operation' => 'video.concat',
                    'mode' => 'copy',
                    'processing_duration_ms' => $concatProcessingMs,
                    'hostname' => gethostname() ?: 'unknown',
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

        Log::info('slate.fanout.completed', [
            'run_id' => $this->run->id->value(),
            'result_object_key' => $this->run->resultKey()->value(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(FanoutRunCleanup::class)->allObjects($this->run);

        Log::error('slate.fanout.failed', [
            'run_id' => $this->run->id->value(),
            'stage' => 'final_concat',
            'message' => $exception?->getMessage(),
        ]);
    }

    private static function monotonicMilliseconds(): int
    {
        return intdiv((int) hrtime(true), 1_000_000);
    }
}
