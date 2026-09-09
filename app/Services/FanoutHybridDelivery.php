<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FinalizeFanoutRun;
use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderSingleJobHybridDelivery;
use App\Slate\Fanout\BucketOperation;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LogicException;
use Throwable;

final readonly class FanoutHybridDelivery
{
    public function __construct(
        private HybridDeliveryAssembly $assembly,
        private JobScratch $jobScratch,
        private ObjectStorage $objectStorage,
        private FanoutRunCleanup $cleanup,
        private FanoutStorageGuard $storageGuard,
    ) {}

    /**
     * Fan-out remains the default after three production-size comparison runs: the one-video medians
     * were lower while the three- and five-video effects stayed within observed noise. The suggestive
     * latency and drain result complements its failure-granularity and timeout benefits, but needs more
     * samples before claiming an effect and is not a throughput claim.
     *
     * @param  non-empty-list<SlateVideo>  $inputs
     */
    public function queue(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ?FanoutRunId $runId = null,
    ): FanoutRun {
        $this->storageGuard->assertSafe();
        $run = $this->prepare($inputs, $transitionFrameCount, $qualityTier, $runId);

        $this->dispatch($run);

        return $run;
    }

    /** @param non-empty-list<SlateVideo> $inputs */
    public function queueRunDelivery(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        FanoutRunId $runId,
    ): FanoutRun {
        $this->storageGuard->assertSafe();
        $run = $this->prepare($inputs, $transitionFrameCount, $qualityTier, $runId, $runId->value());

        $this->dispatch($run);

        return $run;
    }

    /** @param non-empty-list<SlateVideo> $inputs */
    public function queueSingle(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ?FanoutRunId $runId = null,
    ): FanoutRun {
        $this->storageGuard->assertSafe();
        $run = $this->prepare($inputs, $transitionFrameCount, $qualityTier, $runId);

        try {
            Bus::dispatch(new RenderSingleJobHybridDelivery($run));
        } catch (Throwable $exception) {
            $this->cleanup->allObjects($run);

            throw $exception;
        }

        return $run;
    }

    /**
     * Stages source videos before dispatch, so no worker can inherit a path from the initiating host.
     *
     * @param  non-empty-list<SlateVideo>  $inputs
     */
    private function prepare(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ?FanoutRunId $runId = null,
        ?string $managedRunId = null,
    ): FanoutRun {
        $runId ??= FanoutRunId::generate();
        $prefix = $runId->prefix();
        $storedInputs = [];

        foreach ($inputs as $index => $input) {
            $storedInputs[] = StoredVideo::fromRendered(
                $input,
                ObjectKey::withinPrefix($prefix, sprintf('%s/work/inputs/%04d.mp4', $prefix, $index)),
            );
        }

        $operations = $this->jobScratch->run(function (ScratchDirectory $scratch) use (
            $inputs,
            $storedInputs,
            $transitionFrameCount,
            $qualityTier,
            $prefix,
        ): array {
            $localPlan = $this->assembly->plan(
                $inputs,
                $transitionFrameCount,
                $qualityTier,
                $scratch->file('assembled.mp4'),
            );
            $operations = [];
            $inputIndex = 0;

            foreach ($localPlan as $operationIndex => $operation) {
                $outputKey = ObjectKey::withinPrefix(
                    $prefix,
                    sprintf('%s/work/operations/%04d.mp4', $prefix, $operationIndex),
                );

                $bucketOperation = match (true) {
                    $operation instanceof VideoTrim => new BucketVideoTrim(
                        $operationIndex,
                        $storedInputs[$inputIndex],
                        $operation->startFrame,
                        $operation->frameCount,
                        $outputKey,
                    ),
                    $operation instanceof VideoTransitionSegment => new BucketVideoTransitionSegment(
                        $operationIndex,
                        $storedInputs[$inputIndex - 1],
                        $storedInputs[$inputIndex],
                        $operation->frameCount,
                        $operation->qualityTier,
                        $outputKey,
                    ),
                    default => throw new LogicException(sprintf(
                        'Hybrid fan-out cannot map operation type %s.',
                        $operation::class,
                    )),
                };

                $operations[] = $bucketOperation;

                if ($operation instanceof VideoTrim) {
                    $inputIndex++;
                }
            }

            return $operations;
        });
        $run = new FanoutRun($runId, $storedInputs, $operations, $managedRunId);

        try {
            $this->cleanup->allObjects($run);

            foreach ($inputs as $index => $input) {
                $this->objectStorage->stageOut($input->path, $run->inputKey($index));
            }
        } catch (Throwable $exception) {
            $this->cleanup->allObjects($run);

            throw $exception;
        }

        return $run;
    }

    private function dispatch(FanoutRun $run): Batch
    {
        $jobs = array_map(
            static fn (BucketOperation $operation): RenderFanoutOperation => new RenderFanoutOperation(
                $run->id,
                $operation,
            ),
            $run->operations,
        );

        try {
            return Bus::batch($jobs)
                ->name('Slate fan-out '.$run->id->value())
                ->finally(static function (Batch $batch) use ($run): void {
                    dispatch(new FinalizeFanoutRun($run, $batch->hasFailures()));
                })
                ->dispatch();
        } catch (Throwable $exception) {
            $this->cleanup->allObjects($run);

            throw $exception;
        }
    }
}
