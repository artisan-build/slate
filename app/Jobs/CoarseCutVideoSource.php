<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\CoarseCutSource;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\SkipIfBatchUnavailable;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectedException;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Recipe\Video\VideoClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use RuntimeException;
use Throwable;

final class CoarseCutVideoSource implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public string $runId,
        public CoarseCutSource $operation,
    ) {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue((string) config('slate.execution.render_queue'));
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(
        ObjectStorage $objectStorage,
        JobScratch $jobScratch,
        InputAdmission $inputAdmission,
        RecipeCompiler $compiler,
        Driver $driver,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();

        try {
            $jobScratch->run(function (ScratchDirectory $scratch) use ($objectStorage, $inputAdmission, $compiler, $driver): void {
                $extension = pathinfo($this->operation->source->value(), PATHINFO_EXTENSION);
                $source = $scratch->file('source'.($extension === '' ? '' : '.'.$extension));
                $objectStorage->stageIn($this->operation->source, $source);
                $properties = $inputAdmission->inspectVideo($source, $compiler, $driver);

                foreach ($this->operation->ranges as $range) {
                    if ($range->endMicroseconds > (int) floor($properties->durationSeconds * 1_000_000)) {
                        throw new InputRejectedException(
                            InputRejectionReason::ClipRangeInvalid,
                            'A requested clip range extends beyond the source duration.',
                        );
                    }

                    $output = $scratch->file(sprintf('coarse-%04d.mp4', $range->operationIndex));
                    $operation = VideoClip::coarse(
                        $source,
                        $range->startMicroseconds,
                        $range->endMicroseconds,
                        $properties->durationSeconds,
                        $output,
                    );
                    $result = $driver->run($compiler->compile($operation));

                    if ($result->failed()) {
                        throw new RuntimeException('Coarse video clip extraction failed: '.$result->errorOutput);
                    }

                    $objectStorage->stageOut($output, $range->output);
                }
            });
        } catch (InputRejectedException $exception) {
            Run::query()->findOrFail($this->runId)->reject($exception->reason);

            throw $exception;
        }
    }

    /** @return list<SkipIfBatchUnavailable|FailOnException> */
    public function middleware(): array
    {
        return [new SkipIfBatchUnavailable, new FailOnException([InputRejectedException::class])];
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle(
            $this->runId,
            $exception instanceof InputRejectedException ? $exception->reason : null,
        );
    }
}
