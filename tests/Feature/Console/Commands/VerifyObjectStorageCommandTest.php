<?php

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchPath;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

function fakeRssSamples(int $beforeKilobytes, int $peakKilobytes): void
{
    Sleep::fake();
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::describe()->output([(string) $beforeKilobytes, (string) $peakKilobytes]),
    ]);
}

function fakeResolvedS3Disk(bool $streamReads): FilesystemAdapter
{
    $localDisk = Storage::fake('object-storage-verifier-s3', ['driver' => 'local']);
    $adapter = new AwsS3V3Adapter(
        new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]),
        'test-bucket',
        streamReads: $streamReads,
    );
    $disk = new FilesystemAdapter($localDisk->getDriver(), $adapter, ['driver' => 's3']);

    config()->set('filesystems.default', 'resolved-s3');
    Storage::set('resolved-s3', $disk);

    return $disk;
}

test('round-trips the requested object size and emits machine-readable measurements', function (): void {
    Storage::fake(config: ['driver' => 'local']);
    fakeRssSamples(100, 100);

    $exitCode = Artisan::call('slate:verify-object-storage', ['--bytes' => '2097152']);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result)->toMatchArray([
            'configured_driver' => 'local',
            'resolved_driver' => null,
            'resolved_stream_reads' => null,
            'object_size_bytes' => 2097152,
            'exists_after_stage_out' => true,
            'content_identical' => true,
            'rss_growth_to_object_ratio' => 0.0,
            'max_rss_growth_ratio' => 0.1,
            'memory_within_limit' => true,
            'deleted' => true,
            'exists_after_delete' => false,
            'error' => null,
        ]);
    Storage::disk()->assertMissing($result['object_key']);
});

test('fails when the resolved S3 adapter has streamed reads disabled', function (): void {
    $disk = fakeResolvedS3Disk(false);

    $exitCode = Artisan::call('slate:verify-object-storage', ['--bytes' => '1048576']);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($result['resolved_driver'])->toBe('s3')
        ->and($result['resolved_stream_reads'])->toBeFalse()
        ->and($result['error'])->toContain('Resolved S3 disk does not have streamed reads enabled.');
    $disk->assertMissing($result['object_key']);
});

test('fails when RSS growth exceeds ten percent of the object size', function (): void {
    Storage::fake(config: ['driver' => 'local']);
    fakeRssSamples(100, 300);

    $exitCode = Artisan::call('slate:verify-object-storage', ['--bytes' => '1048576']);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($result['rss_growth_to_object_ratio'])->toBe(0.1953)
        ->and($result['max_rss_growth_ratio'])->toBe(0.1)
        ->and($result['memory_within_limit'])->toBeFalse()
        ->and($result['error'])->toContain('RSS growth exceeded 0.1 of object size.')
        ->and($result['deleted'])->toBeTrue();
    Storage::disk()->assertMissing($result['object_key']);
});

test('reports a content mismatch and still deletes the verification object', function (): void {
    Storage::fake(config: ['driver' => 'local']);
    fakeRssSamples(100, 100);
    app()->instance(ObjectStorage::class, new class implements ObjectStorage
    {
        public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void
        {
            file_put_contents($localPath->writablePath(), 'different content');
        }

        public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void
        {
            $source = fopen($localPath->readablePath(), 'rb');

            if (! is_resource($source)) {
                throw new RuntimeException('Unable to open test source.');
            }

            try {
                Storage::disk()->writeStream($objectKey->value(), $source);
            } finally {
                fclose($source);
            }
        }
    });

    $exitCode = Artisan::call('slate:verify-object-storage', ['--bytes' => '1048576']);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($result['error'])->toContain('Round-trip content comparison failed.')
        ->and($result['deleted'])->toBeTrue()
        ->and($result['exists_after_delete'])->toBeFalse();
    Storage::disk()->assertMissing($result['object_key']);
});
