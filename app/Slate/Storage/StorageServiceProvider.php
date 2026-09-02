<?php

namespace App\Slate\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->enableStreamReadsOnDefaultS3Disk();

        $this->app->bind(
            ObjectStorage::class,
            fn (): ObjectStorage => new S3ObjectStorage(Storage::disk()),
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
