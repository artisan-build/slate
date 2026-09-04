<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\WholeOperation;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Throwable;

final class RenderRunOperation implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public string $runId,
        public WholeOperation $operation,
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
            $recipe = $this->operation->recipeOperation;
            $image = $scratch->file($this->localInputName('image', $recipe->image));
            $audio = $scratch->file($this->localInputName('audio', $recipe->audio));
            $objectStorage->stageIn($recipe->image, $image);
            $objectStorage->stageIn($recipe->audio, $audio);
            $rendered = SlateVideo::render(
                $recipe->localOperation($image, $audio, $scratch->file('output.mp4')),
                $compiler,
                $driver,
            );
            $objectStorage->stageOut($rendered->path, $this->operation->outputKey());
        });
    }

    /** @return list<SkipIfBatchCancelled> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->batchId === null) {
            resolve(RunFailure::class)->handle($this->runId);
        }
    }

    private function localInputName(string $label, ObjectKey $key): string
    {
        $extension = pathinfo($key->value(), PATHINFO_EXTENSION);

        return $label.($extension === '' ? '' : '.'.$extension);
    }
}
