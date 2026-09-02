<?php

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\S3ObjectStorage;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Proves stage-in calls readStream and copies that resource to disk, so replacing it with get() fails.
 * It does not exercise an S3 driver, network transport, credentials, or a live bucket.
 */
test('stage-in streams an object to local disk', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/input.mp4');
    $objectStream = fopen('php://temp', 'w+b');
    $localPath = tempnam(sys_get_temp_dir(), 'slate-stage-in-');

    expect($objectStream)->toBeResource()
        ->and($localPath)->toBeString();

    fwrite($objectStream, 'video payload');
    rewind($objectStream);

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('readStream')
        ->once()
        ->with('customers/customer-123/input.mp4')
        ->andReturn($objectStream);

    try {
        (new S3ObjectStorage($filesystem))->stageIn($objectKey, $localPath);

        expect(file_get_contents($localPath))->toBe('video payload');
    } finally {
        @unlink($localPath);
    }
});

/**
 * Proves stage-out passes a local resource to writeStream, so replacing it with put() fails.
 * It does not exercise an S3 driver, multipart upload behavior, network transport, or a live bucket.
 */
test('stage-out streams a local file to an object', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/output.mp4');
    $localPath = tempnam(sys_get_temp_dir(), 'slate-stage-out-');

    expect($localPath)->toBeString();
    file_put_contents($localPath, 'encoded payload');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')
        ->once()
        ->with(
            'customers/customer-123/output.mp4',
            Mockery::on(function (mixed $stream): bool {
                return is_resource($stream) && stream_get_contents($stream) === 'encoded payload';
            }),
        )
        ->andReturnTrue();

    try {
        (new S3ObjectStorage($filesystem))->stageOut($localPath, $objectKey);
    } finally {
        @unlink($localPath);
    }
});

test('stage-in fails when the object cannot be read', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/missing.mp4');
    $localPath = tempnam(sys_get_temp_dir(), 'slate-stage-in-failure-');

    expect($localPath)->toBeString();

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('readStream')
        ->once()
        ->with('customers/customer-123/missing.mp4')
        ->andReturnNull();

    try {
        expect(fn () => (new S3ObjectStorage($filesystem))->stageIn($objectKey, $localPath))
            ->toThrow(RuntimeException::class, 'Unable to read object');
    } finally {
        @unlink($localPath);
    }
});

test('stage-out fails when the object cannot be written', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/output.mp4');
    $localPath = tempnam(sys_get_temp_dir(), 'slate-stage-out-failure-');

    expect($localPath)->toBeString();
    file_put_contents($localPath, 'encoded payload');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')
        ->once()
        ->with('customers/customer-123/output.mp4', Mockery::type('resource'))
        ->andReturnFalse();

    try {
        expect(fn () => (new S3ObjectStorage($filesystem))->stageOut($localPath, $objectKey))
            ->toThrow(RuntimeException::class, 'Unable to write object');
    } finally {
        @unlink($localPath);
    }
});
