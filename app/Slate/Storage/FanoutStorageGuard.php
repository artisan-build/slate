<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use ReflectionProperty;
use RuntimeException;

final readonly class FanoutStorageGuard
{
    public function __construct(
        private Filesystem $filesystem,
        private bool $allowHostLocal,
    ) {}

    public function assertSafe(): void
    {
        $advertisedAdapter = $this->filesystem instanceof FilesystemAdapter
            ? $this->filesystem->getAdapter()
            : null;
        $operationalAdapter = $this->operationalAdapter();

        if ($advertisedAdapter instanceof AwsS3V3Adapter
            && $operationalAdapter === $advertisedAdapter) {
            return;
        }

        if ($this->allowHostLocal) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Slate fan-out requires an S3-backed default filesystem; operational adapter %s, advertised adapter %s. Set SLATE_ALLOW_HOST_LOCAL_FANOUT=true only for single-host local development.',
            $operationalAdapter !== null ? $operationalAdapter::class : $this->filesystem::class,
            $advertisedAdapter !== null ? $advertisedAdapter::class : $this->filesystem::class,
        ));
    }

    private function operationalAdapter(): ?FlysystemAdapter
    {
        if (! $this->filesystem instanceof FilesystemAdapter) {
            return null;
        }

        $driver = $this->filesystem->getDriver();

        if (! $driver instanceof Flysystem) {
            return null;
        }

        $adapter = (new ReflectionProperty(Flysystem::class, 'adapter'))->getValue($driver);

        return $adapter instanceof FlysystemAdapter ? $adapter : null;
    }
}
