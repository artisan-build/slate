<?php

use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\S3ObjectStorage;
use App\Slate\Storage\StorageServiceProvider;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

test('the object storage seam streams from the resolved default S3 disk', function (): void {
    config()->set('filesystems.default', 'cloud-injected');
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://example.test',
    ]);
    Storage::forgetDisk('cloud-injected');

    (new StorageServiceProvider(app()))->register();

    $defaultDisk = Storage::disk();
    $adapter = $defaultDisk->getAdapter();
    $objectStorageFilesystem = (new ReflectionProperty(S3ObjectStorage::class, 'filesystem'))
        ->getValue(resolve(ObjectStorage::class));
    $streamReads = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
        ->getValue($adapter);

    expect($objectStorageFilesystem)->toBe($defaultDisk)
        ->and($adapter)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($streamReads)->toBeTrue();
});

test('an explicit false keeps streamed reads disabled on the default S3 disk', function (): void {
    config()->set('filesystems.default', 'cloud-injected');
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://example.test',
        'stream_reads' => false,
    ]);
    Storage::forgetDisk('cloud-injected');

    (new StorageServiceProvider(app()))->register();

    $adapter = Storage::disk()->getAdapter();
    $streamReads = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
        ->getValue($adapter);

    expect($adapter)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($streamReads)->toBeFalse();
});

test('a scoped default disk streams reads from its inner S3 disk', function (): void {
    config()->set('filesystems.default', 'customer-scope');
    config()->set('filesystems.disks.customer-scope', [
        'driver' => 'scoped',
        'disk' => 'cloud-injected',
        'prefix' => 'customers/customer-123',
    ]);
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://example.test',
    ]);
    Storage::forgetDisk('cloud-injected');

    (new StorageServiceProvider(app()))->register();

    $adapter = Storage::disk('cloud-injected')->getAdapter();
    $streamReads = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
        ->getValue($adapter);

    expect($adapter)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($streamReads)->toBeTrue();
});

test('a non-S3 default disk is left unchanged', function (): void {
    config()->set('filesystems.default', 'cloud-injected');
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/cloud-injected'),
    ]);
    Storage::forgetDisk('cloud-injected');

    (new StorageServiceProvider(app()))->register();

    $defaultDisk = Storage::disk();

    expect($defaultDisk->getAdapter())->toBeInstanceOf(LocalFilesystemAdapter::class)
        ->and($defaultDisk->getConfig())->not->toHaveKey('stream_reads');
});
