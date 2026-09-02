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

        $disk = $disks[$defaultDisk] ?? null;

        if (is_array($disk) && ($disk['driver'] ?? null) === 'scoped') {
            $defaultDisk = $disk['disk'] ?? null;
            $disk = is_string($defaultDisk) ? ($disks[$defaultDisk] ?? null) : null;
        }

        if (! is_array($disk)
            || ($disk['driver'] ?? null) !== 's3'
            || array_key_exists('stream_reads', $disk)) {
            return;
        }

        $disk['stream_reads'] = true;
        $disks[$defaultDisk] = $disk;

        config()->set('filesystems.disks', $disks);
    }
}
