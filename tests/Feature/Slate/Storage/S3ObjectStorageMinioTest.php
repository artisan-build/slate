<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Exercises the real S3 driver, signing, HTTP transport, streamed reads, and multipart-capable writes.
 * It does not exercise Cloudflare R2, Cloud-injected credentials, R2 checksum semantics, or Cloud workers.
 */
test('round-trips through MinIO without driver buffering or process memory buffering', function (): void {
    $socket = @fsockopen('127.0.0.1', 19000, $errorCode, $errorMessage, 0.2);

    if (! is_resource($socket)) {
        test()->markTestSkipped('MinIO unavailable at http://127.0.0.1:19000; real S3-driver lane not run.');
    }

    fclose($socket);

    $configuredDisk = config('filesystems.disks.s3');

    expect($configuredDisk)->toBeArray()
        ->and($configuredDisk['stream_reads'] ?? null)->toBeTrue()
        ->and($configuredDisk['throw'] ?? null)->toBeTrue();

    $diskConfig = [...$configuredDisk, ...[
        'key' => 'slate-minio',
        'secret' => 'slate-minio-secret',
        'region' => 'us-east-1',
        'bucket' => 'slate-tests',
        'endpoint' => 'http://127.0.0.1:19000',
        'use_path_style_endpoint' => true,
    ]];

    $client = new S3Client([
        'version' => 'latest',
        'region' => $diskConfig['region'],
        'endpoint' => $diskConfig['endpoint'],
        'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'],
        'credentials' => [
            'key' => $diskConfig['key'],
            'secret' => $diskConfig['secret'],
        ],
    ]);

    if (! $client->doesBucketExist($diskConfig['bucket'])) {
        $client->createBucket(['Bucket' => $diskConfig['bucket']]);
    }

    config()->set('filesystems.disks.s3', $diskConfig);
    Storage::forgetDisk('s3');

    $objectKey = ObjectKey::withinPrefix('minio-tests', 'minio-tests/round-trip.bin');
    $objectStorage = resolve(ObjectStorage::class);
    $expectedBytes = 20 * 1024 * 1024;
    $block = str_repeat('s', 1024 * 1024);

    try {
        resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
            $block,
            $expectedBytes,
            $objectKey,
            $objectStorage,
        ): void {
            $sourcePath = $scratch->file('source.bin');
            $sourceStream = fopen($sourcePath->writablePath(), 'wb');

            expect($sourceStream)->toBeResource();

            for ($written = 0; $written < $expectedBytes; $written += strlen($block)) {
                fwrite($sourceStream, $block);
            }

            fclose($sourceStream);
            $objectStorage->stageOut($sourcePath, $objectKey);

            $driverStream = Storage::disk('s3')->readStream($objectKey->value());
            expect($driverStream)->toBeResource();
            $metadata = stream_get_meta_data($driverStream);
            fclose($driverStream);

            expect($metadata['uri'])->not->toStartWith('php://temp');

            $destinationPath = $scratch->file('destination.bin');
            $objectStorage->stageIn($objectKey, $destinationPath);

            expect(filesize($destinationPath->readablePath()))->toBe($expectedBytes)
                ->and(hash_file('sha256', $destinationPath->readablePath()))
                ->toBe(hash_file('sha256', $sourcePath->readablePath()));
        });

        $probe = Process::timeout(180)->run([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            base_path('tests/Fixtures/Storage/minio-memory-probe.php'),
        ]);

        expect($probe->successful())->toBeTrue($probe->errorOutput())
            ->and($probe->output())->toContain('round_trip_bytes=100663296');
    } finally {
        Storage::disk('s3')->delete($objectKey->value());
        Storage::disk('s3')->delete('minio-tests/memory-probe.bin');
        Storage::forgetDisk('s3');
    }
})->group('minio');
