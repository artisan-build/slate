<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\FanoutHybridDelivery;
use App\Services\RunFailure;
use App\Slate\Driver\Binary\H264OutputContract;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Run\RunStillClip;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

final class AssembleRunDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public string $runId)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue((string) config('slate.execution.render_queue'));
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(
        ObjectStorage $objectStorage,
        JobScratch $jobScratch,
        RecipeCompiler $compiler,
        Driver $driver,
        FanoutHybridDelivery $delivery,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();
        $run = Run::query()->findOrFail($this->runId);
        $recipe = $run->recipe();

        $jobScratch->run(function (ScratchDirectory $scratch) use (
            $objectStorage,
            $compiler,
            $driver,
            $delivery,
            $recipe,
        ): void {
            $inputs = [];

            foreach ($recipe->operations as $index => $operation) {
                $key = RunObjectKeys::operationOutput($this->runId, $index);
                $localPath = $scratch->file(sprintf('operation-%04d.mp4', $index));
                $objectStorage->stageIn($key, $localPath);
                $stored = match (true) {
                    $operation instanceof RunStillClip => new StoredVideo(
                        $key,
                        $operation->width,
                        $operation->height,
                        $operation->framesPerSecond,
                        (int) round($operation->durationMicroseconds() / 1_000_000 * $operation->framesPerSecond),
                        $operation->durationMicroseconds() / 1_000_000,
                        $operation->qualityTier(),
                        H264OutputContract::keyframeIntervalFrames($operation->framesPerSecond),
                    ),
                    $operation instanceof RunVideoClip => new StoredVideo(
                        $key,
                        1536,
                        1024,
                        24,
                        (int) round($operation->durationMicroseconds() / 1_000_000 * 24),
                        $operation->durationMicroseconds() / 1_000_000,
                        $operation->qualityTier(),
                        H264OutputContract::keyframeIntervalFrames(24),
                    ),
                    default => throw new InvalidArgumentException('Run delivery received an unsupported operation.'),
                };
                $inputs[] = SlateVideo::fromStored($stored, $localPath, $compiler, $driver);
            }

            $delivery->queueRunDelivery(
                $inputs,
                24,
                $recipe->operations[0]->qualityTier(),
                FanoutRunId::fromString($this->runId),
            );
        });
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle($this->runId);
    }
}
