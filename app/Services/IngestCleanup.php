<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingest;
use App\Models\IngestMode;
use App\Models\IngestStatus;
use App\Slate\Storage\IngestStorage;
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

        if ($failures !== []) {
            throw new RuntimeException(
                sprintf('Failed to clean %d abandoned ingests after completing the sweep.', count($failures)),
                previous: $failures[0],
            );
        }

        return $pruned;
    }
}
