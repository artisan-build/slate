<?php

declare(strict_types=1);

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\S3ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Filesystem;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedBytes = 96 * 1024 * 1024;
$objectPath = tempnam(storage_path('framework/testing'), 'slate-object-');

if (! is_string($objectPath)) {
    throw new RuntimeException('Unable to create memory-probe object.');
}

$objectStream = fopen($objectPath, 'w+b');

if (! is_resource($objectStream) || ! ftruncate($objectStream, $expectedBytes)) {
    throw new RuntimeException('Unable to size memory-probe object.');
}

rewind($objectStream);

$filesystem = Mockery::mock(Filesystem::class);
$filesystem->shouldReceive('size')->once()->andReturn($expectedBytes);
$filesystem->shouldReceive('readStream')->once()->andReturn($objectStream);
$filesystem->shouldReceive('writeStream')->once()->andReturnUsing(function (string $key, mixed $stream): bool {
    $sink = fopen('/dev/null', 'wb');

    if (! is_resource($stream) || ! is_resource($sink)) {
        return false;
    }

    try {
        return stream_copy_to_stream($stream, $sink) !== false;
    } finally {
        fclose($sink);
    }
});

$objectKey = ObjectKey::withinPrefix('memory-probe', 'memory-probe/object.bin');
$objectStorage = new S3ObjectStorage($filesystem);

try {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
        $expectedBytes,
        $objectKey,
        $objectStorage,
    ): void {
        $localPath = $scratch->file('object.bin');
        $objectStorage->stageIn($objectKey, $localPath);

        if (filesize($localPath->readablePath()) !== $expectedBytes) {
            throw new RuntimeException('Memory probe stage-in did not preserve the object size.');
        }

        $objectStorage->stageOut($localPath, $objectKey);
    });
} finally {
    @unlink($objectPath);
    Mockery::close();
}

fwrite(STDOUT, "round_trip_bytes={$expectedBytes} peak_memory_bytes=".memory_get_peak_usage(true).PHP_EOL);
