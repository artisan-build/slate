<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use RuntimeException;

final readonly class FanoutStorageGuard
{
    public function __construct(
        private Filesystem $filesystem,
        private bool $allowHostLocal,
    ) {}

    public function assertSafe(): void
    {
        if ($this->filesystem instanceof FilesystemAdapter
            && $this->filesystem->getAdapter() instanceof AwsS3V3Adapter) {
            return;
        }

        if ($this->allowHostLocal) {
            return;
        }

        $adapter = $this->filesystem instanceof FilesystemAdapter
            ? $this->filesystem->getAdapter()::class
            : $this->filesystem::class;

        throw new RuntimeException(sprintf(
            'Slate fan-out requires an S3-backed default filesystem; resolved %s. Set SLATE_ALLOW_HOST_LOCAL_FANOUT=true only for single-host local development.',
            $adapter,
        ));
    }
}
