<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\RunFailure;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Execution\CopyConcatSubClips;
use App\Slate\Execution\MuxOperationAudio;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\RenderSilentSubClip;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoAudioMux;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Run\RunStillClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

final class FinalizeRunSplitOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    /** @var non-empty-list<RenderSilentSubClip> */
    public array $subClips;

    /** @param array<array-key, mixed> $subClips */
    public function __construct(
        public string $runId,
        array $subClips,
        public CopyConcatSubClips $concat,
        public MuxOperationAudio $mux,
    ) {
        if ($subClips === [] || ! array_is_list($subClips)) {
            throw new InvalidArgumentException('Split finalizer requires ordered sub-clips.');
        }

        foreach ($subClips as $subClip) {
            if (! $subClip instanceof RenderSilentSubClip) {
                throw new InvalidArgumentException('Split finalizer accepts only typed silent sub-clips.');
            }
        }

        $subClipKeys = array_map(
            static fn (RenderSilentSubClip $subClip): string => $subClip->outputKey()->value(),
            $subClips,
        );
        $concatKeys = array_map(
            static fn (ObjectKey $input): string => $input->value(),
            $concat->inputs,
        );

        if ($subClipKeys !== $concatKeys
            || $concat->outputKey()->value() !== $mux->videoInput->value()
            || $concat->operationIndex() !== $mux->operationIndex()) {
            throw new InvalidArgumentException('Split finalizer operations must describe one matching bucket handoff.');
        }

        $this->subClips = $subClips;
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
            $videos = [];

            foreach ($this->subClips as $subClip) {
                $localPath = $scratch->file(sprintf('subclip-%04d.mp4', $subClip->subIndex));
                $objectStorage->stageIn($subClip->outputKey(), $localPath);
                $frameCount = max(1, (int) round(
                    $subClip->range->durationMicroseconds / 1_000_000 * $subClip->framesPerSecond,
                ));
                $videos[] = SlateVideo::fromStored(new StoredVideo(
                    $subClip->outputKey(),
                    $subClip->width,
                    $subClip->height,
                    $subClip->framesPerSecond,
                    $frameCount,
                    $subClip->range->durationMicroseconds / 1_000_000,
                    $subClip->qualityTier,
                    $subClip->framesPerSecond,
                    false,
                ), $localPath, $compiler, $driver);
            }

            $first = $videos[0];
            $silent = SlateVideo::render(new VideoConcat(
                $videos,
                $this->concat->mode,
                null,
                0.0,
                false,
                $first->qualityTier,
                $first->framesPerSecond,
                $scratch->file('silent.mp4'),
            ), $compiler, $driver);
            $objectStorage->stageOut($silent->path, $this->concat->outputKey());
            $audioExtension = pathinfo($this->mux->audioInput->value(), PATHINFO_EXTENSION);
            $audio = $scratch->file('audio'.($audioExtension === '' ? '' : '.'.$audioExtension));
            $objectStorage->stageIn($this->mux->audioInput, $audio);
            $recipeOperation = Run::query()->findOrFail($this->runId)->recipe()->operations[$this->mux->operationIndex()] ?? null;

            if (! $recipeOperation instanceof RunStillClip) {
                throw new InvalidArgumentException('Split finalizer requires its persisted still clip operation.');
            }

            $output = SlateVideo::render(new VideoAudioMux(
                $silent,
                $audio,
                $this->mux->audioBitrate,
                $scratch->file($recipeOperation->captions === null ? 'output.mp4' : 'uncaptioned.mp4'),
            ), $compiler, $driver);

            if ($recipeOperation->captions !== null) {
                $font = $scratch->file('font.ttf');
                $objectStorage->stageIn($recipeOperation->captions->font, $font);
                $output = SlateVideo::render(
                    $recipeOperation->captions->localOperation($output, $font, $scratch->file('output.mp4')),
                    $compiler,
                    $driver,
                );
            }

            $objectStorage->stageOut($output->path, $this->mux->outputKey());
        });
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle($this->runId);
    }
}
