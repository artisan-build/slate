<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\PreciseVideoClip;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\SkipIfBatchUnavailable;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RenderVideoClip implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public string $runId,
        public PreciseVideoClip $operation,
    ) {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue((string) config('slate.execution.render_queue'));
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(
        ObjectStorage $objectStorage,
        JobScratch $jobScratch,
        RecipeCompiler $compiler,
        Driver $driver,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();

        $jobScratch->run(function (ScratchDirectory $scratch) use ($objectStorage, $compiler, $driver): void {
            $coarse = $scratch->file('coarse.mp4');
            $objectStorage->stageIn($this->operation->coarseInput, $coarse);
            $output = $scratch->file('output.mp4');
            $rendered = SlateVideo::render(
                VideoClip::precise(
                    $coarse,
                    $this->operation->startMicroseconds,
                    $this->operation->durationMicroseconds,
                    $output,
                ),
                $compiler,
                $driver,
            );
            $objectStorage->stageOut($rendered->path, $this->operation->outputKey());
        });
    }

    /** @return list<SkipIfBatchUnavailable> */
    public function middleware(): array
    {
        return [new SkipIfBatchUnavailable];
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle($this->runId);
    }
}
