<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final readonly class S3ObjectStorage implements ObjectStorage
{
    public function __construct(private Filesystem $filesystem) {}

    public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void
    {
        $resolvedLocalPath = $localPath->writablePath();

        try {
            $expectedBytes = $this->filesystem->size($objectKey->value());
            $objectStream = $this->filesystem->readStream($objectKey->value());
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to read object: {$objectKey->value()}", previous: $exception);
        }

        if (! is_resource($objectStream)) {
            throw new RuntimeException("Unable to read object: {$objectKey->value()}");
        }

        $localStream = @fopen($resolvedLocalPath, 'wb');

        if (! is_resource($localStream)) {
            fclose($objectStream);

            throw new RuntimeException("Unable to open local stage-in path: {$resolvedLocalPath}");
        }

        $copyFailure = null;

        try {
            $copiedBytes = stream_copy_to_stream($objectStream, $localStream);
        } catch (Throwable $exception) {
            $copyFailure = $exception;
            $copiedBytes = false;
        } finally {
            fclose($localStream);
            fclose($objectStream);
        }

        if ($copyFailure instanceof Throwable) {
            @unlink($resolvedLocalPath);

            throw new RuntimeException("Unable to stage object to local path: {$objectKey->value()}", previous: $copyFailure);
        }

        if ($copiedBytes === false || $copiedBytes !== $expectedBytes) {
            @unlink($resolvedLocalPath);

            throw new RuntimeException(
                "Staged object size mismatch for {$objectKey->value()}: expected {$expectedBytes} bytes, copied ".
                ($copiedBytes === false ? 'an unknown number of' : (string) $copiedBytes).' bytes.',
            );
        }
    }

    public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void
    {
        $resolvedLocalPath = $localPath->readablePath();
        $localStream = @fopen($resolvedLocalPath, 'rb');

        if (! is_resource($localStream)) {
            throw new RuntimeException("Unable to open local stage-out path: {$resolvedLocalPath}");
        }

        try {
            if (! $this->filesystem->writeStream($objectKey->value(), $localStream)) {
                throw new RuntimeException("Unable to write object: {$objectKey->value()}");
            }
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to write object: {$objectKey->value()}", previous: $exception);
        } finally {
            fclose($localStream);
        }
    }

    public function exists(ObjectKey $objectKey): bool
    {
        try {
            return $this->filesystem->exists($objectKey->value());
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to inspect object: {$objectKey->value()}", previous: $exception);
        }
    }

    public function delete(ObjectKey $objectKey): void
    {
        try {
            if (! $this->filesystem->delete($objectKey->value())) {
                throw new RuntimeException("Unable to delete object: {$objectKey->value()}");
            }
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to delete object: {$objectKey->value()}", previous: $exception);
        }
    }

    public function keysWithinPrefix(string $prefix): array
    {
        try {
            $paths = $this->filesystem->allFiles($prefix);
        } catch (Throwable $exception) {
            throw new RuntimeException("Unable to list object prefix: {$prefix}", previous: $exception);
        }

        return array_map(
            static fn (string $path): ObjectKey => ObjectKey::withinPrefix($prefix, $path),
            $paths,
        );
    }
}
