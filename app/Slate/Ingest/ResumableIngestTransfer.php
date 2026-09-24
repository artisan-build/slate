<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Http\DestinationGuard;
use App\Slate\Http\ResolvedDestination;
use App\Slate\Input\InputAdmission;
use App\Slate\Storage\IngestStorage;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class ResumableIngestTransfer
{
    private const int MaximumPartCount = 10_000;

    private const int MaximumPartSizeBytes = 5 * 1024 * 1024 * 1024;

    private const int MinimumPartSizeBytes = 5 * 1024 * 1024;

    public function __construct(
        private IngestStorage $storage,
        private SourceReader $sourceReader,
        private InputAdmission $inputAdmission,
        private DestinationGuard $destinationGuard,
    ) {}

    public function handle(Ingest|Source $ingest, int $sourceRequestTimeoutSeconds): void
    {
        if ($ingest->status === IngestStatus::Succeeded || $ingest->status === SourceStatus::Ready) {
            return;
        }

        $destination = $this->destinationGuard->resolve($ingest->sourceUrl(), 'source URL');
        $metadata = $this->sourceReader->metadata($destination, $sourceRequestTimeoutSeconds);
        $this->inputAdmission->inspectSourceMetadata($metadata);
        $ingest->refresh();

        if ($ingest->status->isTerminal()) {
            return;
        }

        $objectKey = $ingest->objectKey();
        $ingest->assertSourceMetadata($metadata);
        $this->inspectInitialBytes($destination, $metadata, $sourceRequestTimeoutSeconds);

        if ($this->storage->exists($objectKey)) {
            if ($this->storage->size($objectKey) !== $metadata->bytes) {
                throw new PermanentIngestException('An ingest object already exists with an unexpected size.');
            }

            return;
        }

        [$ingest, $uploadId, $partSizeBytes] = $this->initializeUpload($ingest, $metadata);
        $uploadedParts = $this->storage->uploadedParts($objectKey, $uploadId);
        $partCount = (int) ceil($metadata->bytes / $partSizeBytes);

        for ($partNumber = 1; $partNumber <= $partCount; $partNumber++) {
            $startByte = ($partNumber - 1) * $partSizeBytes;
            $endByte = min($metadata->bytes - 1, $startByte + $partSizeBytes - 1);
            $contentLength = $endByte - $startByte + 1;

            if (($uploadedParts[$partNumber]['size'] ?? null) === $contentLength) {
                continue;
            }

            $part = $this->sourceReader->read(
                $destination,
                $metadata,
                $startByte,
                $endByte,
                $sourceRequestTimeoutSeconds,
            );

            try {
                $this->inputAdmission->inspectStream($part->stream, false);
                $this->storage->uploadPart(
                    $objectKey,
                    $uploadId,
                    $partNumber,
                    $part->stream,
                    $part->bytes,
                );
            } finally {
                $part->close();
            }
        }

        $completedParts = $this->storage->uploadedParts($objectKey, $uploadId);
        $etags = [];

        for ($partNumber = 1; $partNumber <= $partCount; $partNumber++) {
            $part = $completedParts[$partNumber] ?? null;
            $expectedSize = min($partSizeBytes, $metadata->bytes - (($partNumber - 1) * $partSizeBytes));

            if ($part === null || $part['size'] !== $expectedSize) {
                throw new RuntimeException("Multipart ingest is missing complete part {$partNumber}.");
            }

            $etags[$partNumber] = $part['etag'];
        }

        try {
            $this->storage->completeMultipartUpload($objectKey, $uploadId, $etags);
        } catch (Throwable $exception) {
            if (! $this->storage->exists($objectKey)
                || $this->storage->size($objectKey) !== $metadata->bytes) {
                throw $exception;
            }

            // The object matches but completion was not acknowledged, so the
            // upload may still be live: abort it before dropping the only
            // handle to its billable parts. NoSuchUpload, tolerated by the
            // storage seam, means the completion did land.
            $this->storage->abortMultipartUpload($objectKey, $uploadId);
        }

        $ingest->multipart_upload_id = null;
        $ingest->save();
    }

    /** @return array{Ingest|Source, string, int} */
    private function initializeUpload(Ingest|Source $ingest, SourceMetadata $metadata): array
    {
        $createdUploadId = null;

        try {
            return DB::transaction(function () use ($ingest, $metadata, &$createdUploadId): array {
                $lockedIngest = $ingest instanceof Ingest
                    ? Ingest::query()->lockForUpdate()->findOrFail($ingest->id)
                    : Source::query()->lockForUpdate()->findOrFail($ingest->id);
                $partSizeBytes = $lockedIngest->part_size_bytes ?? $this->partSize($metadata->bytes);
                $uploadId = $lockedIngest->multipart_upload_id;

                if ($uploadId === null) {
                    $uploadId = $this->storage->beginMultipartUpload($lockedIngest->objectKey());
                    $createdUploadId = $uploadId;
                }

                $lockedIngest->beginUpload($metadata, $partSizeBytes, $uploadId);

                return [$lockedIngest, $uploadId, $partSizeBytes];
            });
        } catch (Throwable $exception) {
            if ($createdUploadId === null) {
                throw $exception;
            }

            try {
                $this->storage->abortMultipartUpload($ingest->objectKey(), $createdUploadId);
            } catch (Throwable $abortException) {
                report($exception);

                throw new RuntimeException(
                    'Unable to compensate multipart ingest after state persistence failed.',
                    previous: $abortException,
                );
            }

            throw $exception;
        }
    }

    private function partSize(int $sourceBytes): int
    {
        $configuredPartSize = config('slate.ingest.part_size_bytes');

        if (! is_int($configuredPartSize) || $configuredPartSize < self::MinimumPartSizeBytes) {
            throw new RuntimeException('Ingest part size must be at least five mebibytes.');
        }

        $partSize = max($configuredPartSize, (int) ceil($sourceBytes / self::MaximumPartCount));

        if ($partSize > self::MaximumPartSizeBytes) {
            throw new RuntimeException('The source exceeds the S3 multipart object limit.');
        }

        return $partSize;
    }

    private function inspectInitialBytes(
        ResolvedDestination $destination,
        SourceMetadata $metadata,
        int $sourceRequestTimeoutSeconds,
    ): void {
        $sample = $this->sourceReader->read(
            $destination,
            $metadata,
            0,
            min($metadata->bytes - 1, 64 * 1024 - 1),
            $sourceRequestTimeoutSeconds,
        );

        try {
            $this->inputAdmission->inspectStream($sample->stream, true);
        } finally {
            $sample->close();
        }
    }
}
