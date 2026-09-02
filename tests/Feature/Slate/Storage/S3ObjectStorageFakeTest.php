<?php

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use Illuminate\Support\Facades\Storage;

test('round-trips queued-job data through the configured object disk', function (): void {
    Storage::fake('s3');

    $objectStorage = resolve(ObjectStorage::class);
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/chunks/001.mp4');
    $outputPath = storage_path('framework/testing/slate-stage-out.mp4');
    $inputPath = storage_path('framework/testing/slate-stage-in.mp4');
    file_put_contents($outputPath, 'chunk payload');

    try {
        $objectStorage->stageOut($outputPath, $objectKey);
        @unlink($outputPath);
        $objectStorage->stageIn($objectKey, $inputPath);

        Storage::disk('s3')->assertExists('customers/customer-123/chunks/001.mp4');
        expect(file_get_contents($inputPath))->toBe('chunk payload');
    } finally {
        @unlink($outputPath);
        @unlink($inputPath);
    }
});
