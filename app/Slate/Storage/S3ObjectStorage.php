<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final readonly class S3ObjectStorage implements ObjectStorage
{
    public function __construct(private Filesystem $filesystem) {}

    public static function fromConfiguredDisk(): self
    {
        return new self(Storage::disk('s3'));
    }

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
}
