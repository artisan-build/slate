<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use DateTimeInterface;
use Psr\Http\Message\StreamInterface;

interface IngestStorage
{
    public function exists(ObjectKey $objectKey): bool;

    public function size(ObjectKey $objectKey): int;

    public function beginMultipartUpload(ObjectKey $objectKey): string;

    public function abortMultipartUpload(ObjectKey $objectKey, string $uploadId): void;

    /** @return array<int, array{etag: string, size: int}> */
    public function uploadedParts(ObjectKey $objectKey, string $uploadId): array;

    public function uploadPart(
        ObjectKey $objectKey,
        string $uploadId,
        int $partNumber,
        StreamInterface $stream,
        int $contentLength,
    ): string;

    /** @param array<int, string> $etagsByPartNumber */
    public function completeMultipartUpload(
        ObjectKey $objectKey,
        string $uploadId,
        array $etagsByPartNumber,
    ): void;

    public function delete(ObjectKey $objectKey): void;

    /** @return array{url: string, headers: array<string, list<string>>} */
    public function temporaryUploadUrl(ObjectKey $objectKey, DateTimeInterface $expiration): array;
}
