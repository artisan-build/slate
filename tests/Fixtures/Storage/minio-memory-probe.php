<?php

declare(strict_types=1);

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MinioConfiguration;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configuredDisk = config('filesystems.disks.s3');

if (! is_array($configuredDisk)) {
    throw new RuntimeException('S3 disk is not configured.');
}

config()->set('filesystems.disks.s3', MinioConfiguration::disk($configuredDisk));
Storage::forgetDisk('s3');

$objectKey = ObjectKey::withinPrefix('minio-tests', 'minio-tests/memory-probe.bin');
$objectStorage = resolve(ObjectStorage::class);
$expectedBytes = 96 * 1024 * 1024;
$block = str_repeat('s', 1024 * 1024);

resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
    $block,
    $expectedBytes,
    $objectKey,
    $objectStorage,
): void {
    $sourcePath = $scratch->file('source.bin');
    $sourceStream = fopen($sourcePath->writablePath(), 'wb');

    if (! is_resource($sourceStream)) {
        throw new RuntimeException('Unable to create memory-probe source.');
    }

    for ($written = 0; $written < $expectedBytes; $written += strlen($block)) {
        fwrite($sourceStream, $block);
    }

    fclose($sourceStream);
    $expectedHash = hash_file('sha256', $sourcePath->readablePath());
    $objectStorage->stageOut($sourcePath, $objectKey);
    @unlink($sourcePath->readablePath());

    $destinationPath = $scratch->file('destination.bin');
    $objectStorage->stageIn($objectKey, $destinationPath);

    if (
        filesize($destinationPath->readablePath()) !== $expectedBytes
        || hash_file('sha256', $destinationPath->readablePath()) !== $expectedHash
    ) {
        throw new RuntimeException('MinIO memory probe round trip did not preserve the object.');
    }
});

fwrite(STDOUT, "round_trip_bytes={$expectedBytes} peak_memory_bytes=".memory_get_peak_usage(true).PHP_EOL);
