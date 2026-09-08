<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Source;
use App\Services\IngestCleanup;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\ResumableIngestTransfer;
use App\Slate\Input\InputRejectedException;
use App\Slate\Source\SourceAdmission;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class IngestRemoteSource implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $sourceRequestTimeout;

    public int $maxExceptions = 22;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public string $sourceId)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue(
            (string) config('slate.execution.ingest_queue'),
        );
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->sourceRequestTimeout = $settings->driverTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(
        ResumableIngestTransfer $transfer,
        SourceAdmission $admission,
        IngestCleanup $cleanup,
    ): void {
        $source = Source::query()->findOrFail($this->sourceId);

        try {
            $transfer->handle($source, $this->sourceRequestTimeout);
            $source->refresh();
            $admission->handle($source);
            $source->markReady();
        } catch (InputRejectedException $exception) {
            $cleanup->rejectSource($source->id, $exception->reason);
        }
    }

    public function retryUntil(): DateTimeInterface
    {
        $retryUntil = Source::query()->findOrFail($this->sourceId)->retry_until;

        if (! $retryUntil instanceof DateTimeInterface) {
            throw new LogicException('A remote source requires a persisted retry deadline.');
        }

        return $retryUntil;
    }

    /** @return list<FailOnException> */
    public function middleware(): array
    {
        return [new FailOnException([PermanentIngestException::class, InvalidArgumentException::class])];
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        try {
            resolve(IngestCleanup::class)->failSource($this->sourceId);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
