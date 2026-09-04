<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ingest;
use App\Services\IngestCleanup;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\ResumableIngestTransfer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use LogicException;
use Throwable;

final class IngestSource implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $sourceRequestTimeout;

    public int $maxExceptions = 22;

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

    public function retryUntil(): DateTimeInterface
    {
        $retryUntil = Ingest::query()->findOrFail($this->ingestId)->retry_until;

        if (! $retryUntil instanceof DateTimeInterface) {
            throw new LogicException('A source ingest requires a persisted retry deadline.');
        }

        return $retryUntil;
    }

    /** @return list<FailOnException> */
    public function middleware(): array
    {
        return [new FailOnException([PermanentIngestException::class])];
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        try {
            resolve(IngestCleanup::class)->fail($this->ingestId);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
