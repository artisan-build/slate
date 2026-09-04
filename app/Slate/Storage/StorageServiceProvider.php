<?php

namespace App\Slate\Storage;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->enableStreamReadsOnDefaultS3Disk();

        $this->app->bind(
            ObjectStorage::class,
            fn (): ObjectStorage => new S3ObjectStorage(Storage::disk()),
        );
        $this->app->bind(
            function (): IngestStorage {
                $filesystem = Storage::disk();

                if (! $filesystem instanceof AwsS3V3Adapter) {
                    throw new RuntimeException('Slate ingest requires an S3-backed default filesystem.');
                }

                return new S3IngestStorage($filesystem);
            },
        );
        $this->app->bind(
            FanoutStorageGuard::class,
            fn (): FanoutStorageGuard => new FanoutStorageGuard(
                Storage::disk(),
                (bool) config('slate.storage.allow_host_local_fanout'),
            ),
        );
    }

    private function enableStreamReadsOnDefaultS3Disk(): void
    {
        $defaultDisk = config('filesystems.default');
        $disks = config('filesystems.disks');

        if (! is_string($defaultDisk) || ! is_array($disks)) {
            return;
        }

        $diskName = $defaultDisk;
        $diskNames = [];
        $visitedDiskNames = [];
        $disk = null;

        while (count($visitedDiskNames) < count($disks) && ! isset($visitedDiskNames[$diskName])) {
            $visitedDiskNames[$diskName] = true;
            $diskNames[] = $diskName;
            $disk = $disks[$diskName] ?? null;

            if (! is_array($disk) || ($disk['driver'] ?? null) !== 'scoped') {
                break;
            }

            $diskName = $disk['disk'] ?? null;

            if (! is_string($diskName)) {
                return;
            }
        }

        if (! is_array($disk)
            || ($disk['driver'] ?? null) !== 's3'
            || array_key_exists('stream_reads', $disk)) {
            return;
        }

        $disk['stream_reads'] = true;
        $disks[$diskName] = $disk;

        config()->set('filesystems.disks', $disks);
        Storage::forgetDisk($diskNames);
    }
}
