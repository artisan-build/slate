<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ingest;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Ingest\ResumableIngestTransfer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class IngestSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public int $sourceRequestTimeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public string $ingestId)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue(
            (string) config('slate.execution.ingest_queue'),
        );
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->sourceRequestTimeout = $settings->driverTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(ResumableIngestTransfer $transfer): void
    {
        $ingest = Ingest::query()->findOrFail($this->ingestId);
        $transfer->handle($ingest, $this->sourceRequestTimeout);
        // Completion stays separate so ingest-time admission can run here before success.
        $ingest->markSucceeded();
    }

    public function failed(?Throwable $exception): void
    {
        Ingest::query()->find($this->ingestId)?->markFailed();
    }
}
