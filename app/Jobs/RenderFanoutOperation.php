<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Fanout\BucketOperation;
use App\Slate\Fanout\FanoutReceipt;
use App\Slate\Fanout\FanoutRunId;
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
use RuntimeException;

final class RenderFanoutOperation implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(
        public FanoutRunId $runId,
        public BucketOperation $operation,
    ) {}

    /** @return list<SkipIfBatchCancelled> */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function handle(
        ObjectStorage $objectStorage,
        JobScratch $jobScratch,
        RecipeCompiler $compiler,
        Driver $driver,
        FanoutStorageGuard $storageGuard,
    ): void {
        $storageGuard->assertSafe();
        $startedAtMs = self::monotonicMilliseconds();

        $jobScratch->run(function (ScratchDirectory $scratch) use (
            $objectStorage,
            $compiler,
            $driver,
            $startedAtMs,
        ): void {
            $localInputs = [];

            foreach ($this->operation->inputs() as $index => $storedInput) {
                $localPath = $scratch->file(sprintf('input-%02d.mp4', $index));
                $objectStorage->stageIn($storedInput->objectKey, $localPath);
                $localInputs[] = SlateVideo::fromStored($storedInput, $localPath, $compiler, $driver);
            }

            $localOutput = $scratch->file('output.mp4');
            $rendered = SlateVideo::render(
                $this->operation->localOperation($localInputs, $localOutput),
                $compiler,
                $driver,
            );
            $objectStorage->stageOut($rendered->path, $this->operation->output()->objectKey);
            $outputSizeBytes = filesize($rendered->path->readablePath());

            if ($outputSizeBytes === false || $outputSizeBytes < 1) {
                throw new RuntimeException('Unable to measure fan-out operation output.');
            }

            $receipt = FanoutReceipt::capture(
                $this->operation,
                self::monotonicMilliseconds() - $startedAtMs,
                $outputSizeBytes,
            );
            $receiptPath = $scratch->file('receipt.json');
            file_put_contents($receiptPath->writablePath(), $receipt->toJson());
            $objectStorage->stageOut($receiptPath, $this->receiptKey());
        });
    }

    private function receiptKey(): ObjectKey
    {
        $prefix = $this->runId->prefix();

        return ObjectKey::withinPrefix(
            $prefix,
            sprintf('%s/work/receipts/%04d.json', $prefix, $this->operation->index()),
        );
    }

    private static function monotonicMilliseconds(): int
    {
        return intdiv((int) hrtime(true), 1_000_000);
    }
}
