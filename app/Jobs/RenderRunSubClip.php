<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\RenderSilentSubClip;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RenderRunSubClip implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public string $runId,
        public RenderSilentSubClip $operation,
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
            $extension = pathinfo($this->operation->imageInput->value(), PATHINFO_EXTENSION);
            $image = $scratch->file('image'.($extension === '' ? '' : '.'.$extension));
            $objectStorage->stageIn($this->operation->imageInput, $image);
            $rendered = SlateVideo::render(
                $this->operation->localOperation(
                    $image,
                    $scratch->file(sprintf('subclip-%04d.mp4', $this->operation->subIndex)),
                ),
                $compiler,
                $driver,
            );
            $objectStorage->stageOut($rendered->path, $this->operation->outputKey());
        });
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle($this->runId);
    }
}
