<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

final readonly class S3ObjectStorage implements ObjectStorage
{
    public function __construct(private Filesystem $filesystem) {}

    public function stageIn(ObjectKey $objectKey, string $localPath): void
    {
        $objectStream = $this->filesystem->readStream($objectKey->value());

        if (! is_resource($objectStream)) {
            throw new RuntimeException("Unable to read object: {$objectKey->value()}");
        }

        $localStream = fopen($localPath, 'wb');

        if (! is_resource($localStream)) {
            fclose($objectStream);

            throw new RuntimeException("Unable to open local stage-in path: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($objectStream, $localStream) === false) {
                throw new RuntimeException("Unable to stage object to local path: {$objectKey->value()}");
            }
        } catch (\Throwable $exception) {
            @unlink($localPath);

            throw $exception;
        } finally {
            fclose($localStream);
            fclose($objectStream);
        }
    }

    public function stageOut(string $localPath, ObjectKey $objectKey): void
    {
        $localStream = fopen($localPath, 'rb');

        if (! is_resource($localStream)) {
            throw new RuntimeException("Unable to open local stage-out path: {$localPath}");
        }

        try {
            if (! $this->filesystem->writeStream($objectKey->value(), $localStream)) {
                throw new RuntimeException("Unable to write object: {$objectKey->value()}");
            }
        } finally {
            fclose($localStream);
        }
    }
}
