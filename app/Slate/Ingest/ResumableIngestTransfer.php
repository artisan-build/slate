<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Models\Ingest;
use App\Models\IngestStatus;
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
    ) {}

    public function handle(Ingest $ingest, int $sourceRequestTimeoutSeconds): void
    {
        if ($ingest->status === IngestStatus::Succeeded) {
            return;
        }

        $metadata = $this->sourceReader->metadata($ingest->sourceUrl(), $sourceRequestTimeoutSeconds);
        $objectKey = $ingest->objectKey();

        if ($this->storage->exists($objectKey)) {
            if ($this->storage->size($objectKey) !== $metadata->bytes) {
                throw new RuntimeException('An ingest object already exists with an unexpected size.');
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
                $ingest->sourceUrl(),
                $metadata,
                $startByte,
                $endByte,
                $sourceRequestTimeoutSeconds,
            );

            try {
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
        }

    }

    /** @return array{Ingest, string, int} */
    private function initializeUpload(Ingest $ingest, SourceMetadata $metadata): array
    {
        return DB::transaction(function () use ($ingest, $metadata): array {
            $lockedIngest = Ingest::query()->lockForUpdate()->findOrFail($ingest->id);
            $partSizeBytes = $lockedIngest->part_size_bytes ?? $this->partSize($metadata->bytes);
            $uploadId = $lockedIngest->multipart_upload_id
                ?? $this->storage->beginMultipartUpload($lockedIngest->objectKey());
            $lockedIngest->beginUpload($metadata, $partSizeBytes, $uploadId);

            return [$lockedIngest, $uploadId, $partSizeBytes];
        });
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
}
