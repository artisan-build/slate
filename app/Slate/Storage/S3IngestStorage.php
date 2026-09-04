<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use League\Flysystem\PathPrefixer;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final readonly class S3IngestStorage implements IngestStorage
{
    public function __construct(private AwsS3V3Adapter $filesystem) {}

    public function exists(ObjectKey $objectKey): bool
    {
        return $this->filesystem->exists($objectKey->value());
    }

    public function size(ObjectKey $objectKey): int
    {
        return $this->filesystem->size($objectKey->value());
    }

    public function beginMultipartUpload(ObjectKey $objectKey): string
    {
        try {
            $result = $this->filesystem->getClient()->createMultipartUpload($this->objectArguments($objectKey));
            $uploadId = $result['UploadId'] ?? null;
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to begin multipart ingest: {$objectKey->value()}", previous: $exception);
        }

        if (! is_string($uploadId) || $uploadId === '') {
            throw new RuntimeException("Multipart ingest did not return an upload ID: {$objectKey->value()}");
        }

        return $uploadId;
    }

    public function uploadedParts(ObjectKey $objectKey, string $uploadId): array
    {
        $parts = [];

        try {
            foreach ($this->filesystem->getClient()->getPaginator('ListParts', [
                ...$this->objectArguments($objectKey),
                'UploadId' => $uploadId,
            ]) as $result) {
                foreach ($result['Parts'] ?? [] as $part) {
                    $partNumber = $this->positiveInteger($part['PartNumber'] ?? null);
                    $etag = $part['ETag'] ?? null;
                    $size = $this->positiveInteger($part['Size'] ?? null);

                    if ($partNumber === null || ! is_string($etag) || $size === null) {
                        throw new RuntimeException('Multipart ingest returned invalid part metadata.');
                    }

                    $parts[$partNumber] = ['etag' => $etag, 'size' => $size];
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to inspect multipart ingest: {$objectKey->value()}", previous: $exception);
        }

        ksort($parts);

        return $parts;
    }

    public function uploadPart(
        ObjectKey $objectKey,
        string $uploadId,
        int $partNumber,
        StreamInterface $stream,
        int $contentLength,
    ): string {
        try {
            $result = $this->filesystem->getClient()->uploadPart([
                ...$this->objectArguments($objectKey),
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $stream,
                'ContentLength' => $contentLength,
            ]);
            $etag = $result['ETag'] ?? null;
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to upload ingest part {$partNumber}: {$objectKey->value()}", previous: $exception);
        }

        if (! is_string($etag) || $etag === '') {
            throw new RuntimeException("Ingest part {$partNumber} did not return an ETag: {$objectKey->value()}");
        }

        return $etag;
    }

    public function completeMultipartUpload(
        ObjectKey $objectKey,
        string $uploadId,
        array $etagsByPartNumber,
    ): void {
        ksort($etagsByPartNumber);
        $parts = [];

        foreach ($etagsByPartNumber as $partNumber => $etag) {
            if (! is_int($partNumber) || $partNumber < 1 || ! is_string($etag) || $etag === '') {
                throw new RuntimeException('Multipart completion requires ordered part ETags.');
            }

            $parts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
        }

        try {
            $this->filesystem->getClient()->completeMultipartUpload([
                ...$this->objectArguments($objectKey),
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to complete multipart ingest: {$objectKey->value()}", previous: $exception);
        }
    }

    public function temporaryUploadUrl(ObjectKey $objectKey, DateTimeInterface $expiration): array
    {
        /** @var array{url: string, headers: array<string, list<string>>} $upload */
        $upload = $this->filesystem->temporaryUploadUrl($objectKey->value(), $expiration);

        return $upload;
    }

    /** @return array{Bucket: string, Key: string} */
    private function objectArguments(ObjectKey $objectKey): array
    {
        $config = $this->filesystem->getConfig();
        $bucket = $config['bucket'] ?? null;
        $root = $config['root'] ?? '';

        if (! is_string($bucket) || $bucket === '' || ! is_string($root)) {
            throw new RuntimeException('The S3 ingest disk requires a bucket and string root.');
        }

        return [
            'Bucket' => $bucket,
            'Key' => (new PathPrefixer($root))->prefixPath($objectKey->value()),
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
