<?php

use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\S3ObjectStorage;
use App\Slate\Storage\StorageServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

test('the application registers the object storage provider', function (): void {
    expect(resolve(ObjectStorage::class))->toBeInstanceOf(S3ObjectStorage::class);
});

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

test('a cached default S3 disk is rebuilt after streamed reads are enabled', function (): void {
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
    $cachedDisk = Storage::disk();

    (new StorageServiceProvider(app()))->register();

    $resolvedDisk = Storage::disk();
    $streamReads = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
        ->getValue($resolvedDisk->getAdapter());

    expect($resolvedDisk)->not->toBe($cachedDisk)
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

test('a nested scoped default disk streams reads through its object storage seam', function (): void {
    Storage::extend('scoped', function ($app, array $config): FilesystemAdapter {
        $innerDisk = Storage::disk($config['disk']);

        return new FilesystemAdapter($innerDisk->getDriver(), $innerDisk->getAdapter(), $config);
    });
    config()->set('filesystems.default', 'customer-scope');
    config()->set('filesystems.disks.customer-scope', [
        'driver' => 'scoped',
        'disk' => 'project-scope',
        'prefix' => 'customers/customer-123',
    ]);
    config()->set('filesystems.disks.project-scope', [
        'driver' => 'scoped',
        'disk' => 'cloud-injected',
        'prefix' => 'projects/project-456',
    ]);
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://example.test',
    ]);
    Storage::forgetDisk(['customer-scope', 'project-scope', 'cloud-injected']);

    (new StorageServiceProvider(app()))->register();

    $objectStorageFilesystem = (new ReflectionProperty(S3ObjectStorage::class, 'filesystem'))
        ->getValue(resolve(ObjectStorage::class));
    $adapter = $objectStorageFilesystem->getAdapter();
    $streamReads = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
        ->getValue($adapter);

    expect($objectStorageFilesystem)->toBe(Storage::disk())
        ->and($adapter)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->and($streamReads)->toBeTrue();
});

test('a cycle in scoped default disks terminates without changing either disk', function (): void {
    config()->set('filesystems.default', 'first-scope');
    config()->set('filesystems.disks.first-scope', [
        'driver' => 'scoped',
        'disk' => 'second-scope',
        'prefix' => 'first',
    ]);
    config()->set('filesystems.disks.second-scope', [
        'driver' => 'scoped',
        'disk' => 'first-scope',
        'prefix' => 'second',
    ]);

    (new StorageServiceProvider(app()))->register();

    expect(config('filesystems.disks.first-scope'))->not->toHaveKey('stream_reads')
        ->and(config('filesystems.disks.second-scope'))->not->toHaveKey('stream_reads');
});

test('a non-S3 default disk is not mutated and fails the fan-out guard', function (): void {
    config()->set('filesystems.default', 'cloud-injected');
    config()->set('filesystems.disks.cloud-injected', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/cloud-injected'),
    ]);
    Storage::forgetDisk('cloud-injected');
    config()->set('slate.storage.allow_host_local_fanout', false);

    (new StorageServiceProvider(app()))->register();

    $defaultDisk = Storage::disk();

    expect($defaultDisk->getAdapter())->toBeInstanceOf(LocalFilesystemAdapter::class)
        ->and($defaultDisk->getConfig())->not->toHaveKey('stream_reads');
    expect(fn () => resolve(FanoutStorageGuard::class)->assertSafe())
        ->toThrow(RuntimeException::class, 'Slate fan-out requires an S3-backed default filesystem');
});
