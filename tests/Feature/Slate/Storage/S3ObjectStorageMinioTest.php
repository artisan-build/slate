<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MinioConfiguration;

/**
 * Exercises the real S3 driver, signing, HTTP transport, streamed reads, and multipart-capable writes.
 * It does not exercise Cloudflare R2, Cloud-injected credentials, R2 checksum semantics, or Cloud workers.
 */
test('round-trips through MinIO without driver buffering or process memory buffering', function (): void {
    $configuredDisk = config('filesystems.disks.s3');

    expect($configuredDisk)->toBeArray()
        ->and($configuredDisk['stream_reads'] ?? null)->toBeTrue()
        ->and($configuredDisk['throw'] ?? null)->toBeTrue();

    $socket = @fsockopen(
        MinioConfiguration::Host,
        MinioConfiguration::Port,
        $errorCode,
        $errorMessage,
        0.2,
    );

    if (! is_resource($socket)) {
        $message = 'MinIO unavailable at '.MinioConfiguration::Endpoint.'; real S3-driver lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_MINIO'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_MINIO requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    fclose($socket);

    $diskConfig = MinioConfiguration::disk($configuredDisk);

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
