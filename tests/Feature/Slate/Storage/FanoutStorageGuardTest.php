<?php

use App\Slate\Storage\FanoutStorageGuard;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

test('rejects a host-local default disk unless the explicit development opt-out is enabled', function (): void {
    Storage::fake(config: ['driver' => 'local']);
    config()->set('slate.storage.allow_host_local_fanout', false);

    expect(fn () => resolve(FanoutStorageGuard::class)->assertSafe())
        ->toThrow(RuntimeException::class, 'Slate fan-out requires an S3-backed default filesystem');

    config()->set('slate.storage.allow_host_local_fanout', true);

    $guard = resolve(FanoutStorageGuard::class);
    expect($guard)->toBeInstanceOf(FanoutStorageGuard::class);
    $guard->assertSafe();
});

test('rejects S3 metadata wrapped around a local operational driver', function (): void {
    $localDisk = Storage::fake('fanout-guard-s3', ['driver' => 'local']);
    $adapter = new AwsS3V3Adapter(
        new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]),
        'test-bucket',
        streamReads: true,
    );
    $disk = new FilesystemAdapter($localDisk->getDriver(), $adapter, ['driver' => 's3']);
    config()->set('filesystems.default', 'resolved-s3');
    config()->set('slate.storage.allow_host_local_fanout', false);
    Storage::set('resolved-s3', $disk);

    expect(fn () => resolve(FanoutStorageGuard::class)->assertSafe())
        ->toThrow(RuntimeException::class, 'operational adapter League\Flysystem\Local\LocalFilesystemAdapter, advertised adapter League\Flysystem\AwsS3V3\AwsS3V3Adapter');
});

test('accepts an operational S3 adapter without the host-local opt-out', function (): void {
    $adapter = new AwsS3V3Adapter(
        new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]),
        'test-bucket',
        streamReads: true,
    );
    $disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 's3']);
    config()->set('filesystems.default', 'resolved-s3');
    config()->set('slate.storage.allow_host_local_fanout', false);
    Storage::set('resolved-s3', $disk);

    $guard = resolve(FanoutStorageGuard::class);
    expect($guard)->toBeInstanceOf(FanoutStorageGuard::class);
    $guard->assertSafe();
});
