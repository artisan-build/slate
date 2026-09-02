<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Support\Facades\Storage;

/**
 * Proves the container binding round-trips bytes through the ObjectStorage seam on a local fake disk.
 * It does not construct the S3 driver or exercise credentials, signing, transport, multipart, or R2.
 */
test('round-trips job data through the object-storage seam on a local fake disk', function (): void {
    $resolvedBeforeFake = resolve(ObjectStorage::class);
    Storage::fake();

    $objectStorage = resolve(ObjectStorage::class);
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/chunks/001.mp4');

    expect($objectStorage)->not->toBe($resolvedBeforeFake);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($objectStorage, $objectKey): void {
        $outputPath = $scratch->file('stage-out.mp4');
        $inputPath = $scratch->file('stage-in.mp4');
        file_put_contents($outputPath->writablePath(), 'chunk payload');

        $objectStorage->stageOut($outputPath, $objectKey);
        $objectStorage->stageIn($objectKey, $inputPath);

        Storage::disk()->assertExists('customers/customer-123/chunks/001.mp4');
        expect(file_get_contents($inputPath->readablePath()))->toBe('chunk payload');
    });
});
