<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Source;
use App\Models\SourceStatus;
use App\Services\IngestCleanup;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Input\InputRejectedException;
use App\Slate\Source\SourceAdmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class FinalizeSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public string $sourceId)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue(
            (string) config('slate.execution.ingest_queue'),
        );
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(SourceAdmission $admission, IngestCleanup $cleanup): void
    {
        $source = Source::query()->findOrFail($this->sourceId);

        if ($source->status !== SourceStatus::Ingesting) {
            return;
        }

        try {
            $admission->handle($source);
            $source->markReady();
        } catch (InputRejectedException $exception) {
            $cleanup->rejectSource($source->id, $exception->reason);
        }
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
