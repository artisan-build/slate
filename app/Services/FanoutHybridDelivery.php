<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FinalizeFanoutRun;
use App\Jobs\RenderFanoutOperation;
use App\Slate\Fanout\BucketOperation;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Throwable;

final readonly class FanoutHybridDelivery
{
    public function __construct(
        private HybridDeliveryAssembly $assembly,
        private JobScratch $jobScratch,
        private ObjectStorage $objectStorage,
        private FanoutRunCleanup $cleanup,
    ) {}

    /** @param non-empty-list<SlateVideo> $inputs */
    public function queue(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ?FanoutRunId $runId = null,
    ): FanoutRun {
        $run = $this->prepare($inputs, $transitionFrameCount, $qualityTier, $runId);

        $this->dispatch($run);

        return $run;
    }

    /**
     * Stages source videos before dispatch, so no worker can inherit a path from the initiating host.
     *
     * @param  non-empty-list<SlateVideo>  $inputs
     */
    public function prepare(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ?FanoutRunId $runId = null,
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

                if ($operation instanceof VideoTrim) {
                    $operations[] = new BucketVideoTrim(
                        $operationIndex,
                        $storedInputs[$inputIndex],
                        $operation->startFrame,
                        $operation->frameCount,
                        $outputKey,
                    );
                    $inputIndex++;

                    continue;
                }

                $operations[] = new BucketVideoTransitionSegment(
                    $operationIndex,
                    $storedInputs[$inputIndex - 1],
                    $storedInputs[$inputIndex],
                    $operation->frameCount,
                    $operation->qualityTier,
                    $outputKey,
                );
            }

            return $operations;
        });
        $run = new FanoutRun($runId, $storedInputs, $operations);

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

    public function dispatch(FanoutRun $run): Batch
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
