<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\S3ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

test('moves an object larger than its process memory headroom without buffering it', function (): void {
    $probe = Process::timeout(120)->run([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        base_path('tests/Fixtures/Storage/memory-probe.php'),
    ]);

    expect($probe->successful())->toBeTrue($probe->errorOutput())
        ->and($probe->output())->toContain('round_trip_bytes=100663296');
});

/**
 * Proves stage-in calls size/readStream and copies that resource to scratch, so using get() fails.
 * It does not exercise an S3 driver, network transport, credentials, a live bucket, or independently
 * prove that an alternative implementation does not buffer the object in memory.
 */
test('stage-in streams a complete object to scratch', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/input.mp4');
    $objectStream = fopen('php://temp', 'w+b');

    expect($objectStream)->toBeResource();
    fwrite($objectStream, 'video payload');
    rewind($objectStream);

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('size')->once()->with($objectKey->value())->andReturn(13);
    $filesystem->shouldReceive('readStream')->once()->with($objectKey->value())->andReturn($objectStream);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filesystem, $objectKey): void {
        $localPath = $scratch->file('input.mp4');

        (new S3ObjectStorage($filesystem))->stageIn($objectKey, $localPath);

        expect(file_get_contents($localPath->readablePath()))->toBe('video payload');
    });
});

/**
 * Proves stage-out passes a scratch-file resource to writeStream, so replacing it with put() fails.
 * It does not exercise an S3 driver, multipart behavior, network transport, credentials, a live bucket,
 * or independently prove that an alternative implementation does not buffer the object in memory.
 */
test('stage-out streams a scratch file to an object', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/output.mp4');
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')
        ->once()
        ->with(
            $objectKey->value(),
            Mockery::on(fn (mixed $stream): bool => is_resource($stream) && stream_get_contents($stream) === 'encoded payload'),
        )
        ->andReturnTrue();

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filesystem, $objectKey): void {
        $localPath = $scratch->file('output.mp4');
        file_put_contents($localPath->writablePath(), 'encoded payload');

        (new S3ObjectStorage($filesystem))->stageOut($localPath, $objectKey);
    });
});

test('stage-in rejects a truncated object and deletes the partial file', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/input.mp4');
    $objectStream = fopen('php://temp', 'w+b');

    expect($objectStream)->toBeResource();
    fwrite($objectStream, '48 bytes would already be too short for this object');
    rewind($objectStream);

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('size')->once()->with($objectKey->value())->andReturn(2_848_208);
    $filesystem->shouldReceive('readStream')->once()->with($objectKey->value())->andReturn($objectStream);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filesystem, $objectKey): void {
        $localPath = $scratch->file('input.mp4');

        expect(fn () => (new S3ObjectStorage($filesystem))->stageIn($objectKey, $localPath))
            ->toThrow(RuntimeException::class, 'expected 2848208 bytes');
        expect(file_exists($localPath->writablePath()))->toBeFalse();
    });
});

test('stage-in preserves the driver failure as the previous exception', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/missing.mp4');
    $driverFailure = new DomainException('connection refused');
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('size')->once()->with($objectKey->value())->andThrow($driverFailure);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filesystem, $objectKey, $driverFailure): void {
        try {
            (new S3ObjectStorage($filesystem))->stageIn($objectKey, $scratch->file('missing.mp4'));
            test()->fail('Expected stage-in to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getPrevious())->toBe($driverFailure);
        }
    });
});

test('stage-out preserves the driver failure as the previous exception', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/output.mp4');
    $driverFailure = new DomainException('access denied');
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')->once()->andThrow($driverFailure);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filesystem, $objectKey, $driverFailure): void {
        $localPath = $scratch->file('output.mp4');
        file_put_contents($localPath->writablePath(), 'encoded payload');

        try {
            (new S3ObjectStorage($filesystem))->stageOut($localPath, $objectKey);
            test()->fail('Expected stage-out to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getPrevious())->toBe($driverFailure);
        }
    });
});
