<?php

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchPath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

test('round-trips the requested object size and emits machine-readable measurements', function (): void {
    Storage::fake(config: ['driver' => 'local']);

    $exitCode = Artisan::call('slate:verify-object-storage', ['--bytes' => '2097152']);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result)->toMatchArray([
            'configured_driver' => 'local',
            'resolved_stream_reads' => null,
            'object_size_bytes' => 2097152,
            'exists_after_stage_out' => true,
            'content_identical' => true,
            'deleted' => true,
            'exists_after_delete' => false,
            'error' => null,
        ])
        ->and($result['peak_rss_during_read_bytes'])->toBeGreaterThanOrEqual($result['rss_before_read_bytes']);
    Storage::disk()->assertMissing($result['object_key']);
});

test('reports a content mismatch and still deletes the verification object', function (): void {
    Storage::fake(config: ['driver' => 'local']);
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
