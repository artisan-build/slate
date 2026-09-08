<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingest;
use App\Models\IngestMode;
use App\Models\IngestStatus;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Storage\IngestStorage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

final readonly class IngestCleanup
{
    public function __construct(private IngestStorage $storage) {}

    public function fail(string $ingestId): void
    {
        $ingest = Ingest::query()->findOrFail($ingestId);
        $ingest->markFailed();

        if ($ingest->status !== IngestStatus::Failed) {
            return;
        }

        $failures = [];

        if ($ingest->multipart_upload_id !== null) {
            try {
                $this->storage->abortMultipartUpload($ingest->objectKey(), $ingest->multipart_upload_id);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        try {
            if ($this->storage->exists($ingest->objectKey())) {
                $this->storage->delete($ingest->objectKey());
            }
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                report($failure);
            }

            throw new RuntimeException('Unable to clean all storage for failed ingest.', previous: $failures[0]);
        }
    }

    public function sweepAbandoned(?DateTimeInterface $at = null): int
    {
        $pruned = 0;
        $failures = [];
        $at ??= now();
        $staleSourceSeconds = config('slate.ingest.stale_source_ingesting_seconds');

        if (! is_int($staleSourceSeconds) || $staleSourceSeconds < 1) {
            throw new RuntimeException('The stale source ingest threshold must be a positive integer.');
        }

        $staleSourceBefore = CarbonImmutable::instance($at)->subSeconds($staleSourceSeconds);

        foreach (Ingest::query()
            ->where(function ($query) use ($at): void {
                $query->where('status', IngestStatus::Failed->value)
                    ->orWhere(function ($query) use ($at): void {
                        $query->where('mode', IngestMode::Direct->value)
                            ->where('status', IngestStatus::Expected->value)
                            ->where('expires_at', '<=', $at);
                    });
            })
            ->lazyById() as $ingest) {
            try {
                $this->fail($ingest->id);
                $pruned++;
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        foreach (Source::query()
            ->whereIn('status', [SourceStatus::Failed->value, SourceStatus::Rejected->value])
            ->orWhere(function ($query) use ($at): void {
                $query->where('mode', IngestMode::Direct->value)
                    ->where('status', SourceStatus::Pending->value)
                    ->where('expires_at', '<=', $at);
            })
            ->orWhere(function ($query) use ($staleSourceBefore): void {
                $query->where('status', SourceStatus::Ingesting->value)
                    ->where('updated_at', '<=', $staleSourceBefore);
            })
            ->lazyById() as $source) {
            try {
                $this->failSource($source->id);
                $pruned++;
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                sprintf('Failed to clean %d abandoned ingests after completing the sweep.', count($failures)),
                previous: $failures[0],
            );
        }

        return $pruned;
    }

    public function failSource(string $sourceId): void
    {
        $source = Source::query()->findOrFail($sourceId);
        $source->markFailed();

        if (! in_array($source->status, [SourceStatus::Failed, SourceStatus::Rejected], true)) {
            return;
        }

        $this->cleanSourceStorage($source);
    }

    public function rejectSource(string $sourceId, InputRejectionReason $reason): void
    {
        $source = Source::query()->findOrFail($sourceId);
        $source->reject($reason);

        if ($source->status !== SourceStatus::Rejected) {
            return;
        }

        $this->cleanSourceStorage($source);
    }

    private function cleanSourceStorage(Source $source): void
    {
        $failures = [];

        if ($source->multipart_upload_id !== null) {
            try {
                $this->storage->abortMultipartUpload($source->objectKey(), $source->multipart_upload_id);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        try {
            if ($this->storage->exists($source->objectKey())) {
                $this->storage->delete($source->objectKey());
            }
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                report($failure);
            }

            throw new RuntimeException('Unable to clean all storage for failed source.', previous: $failures[0]);
        }
    }
}
