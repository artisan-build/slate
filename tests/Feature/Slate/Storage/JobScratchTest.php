<?php

use App\Slate\Storage\JobScratch;
use Illuminate\Filesystem\Filesystem;

test('removes per-job scratch after successful work', function (): void {
    $scratchRoot = storage_path('framework/testing/slate-job-scratch-success');
    config()->set('slate.storage.scratch_path', $scratchRoot);
    $scratchPath = null;

    $result = app(JobScratch::class)->run(function (string $path) use (&$scratchPath, $scratchRoot): string {
        $scratchPath = $path;

        expect(is_dir($path))->toBeTrue()
            ->and(str_starts_with($path, $scratchRoot.DIRECTORY_SEPARATOR))->toBeTrue();

        file_put_contents($path.'/output.mp4', 'encoded payload');

        return 'complete';
    });

    expect($result)->toBe('complete')
        ->and($scratchPath)->toBeString()
        ->and(is_dir($scratchPath))->toBeFalse();

    (new Filesystem)->deleteDirectory($scratchRoot);
});

test('removes per-job scratch when work fails and preserves the exception', function (): void {
    $scratchRoot = storage_path('framework/testing/slate-job-scratch-failure');
    config()->set('slate.storage.scratch_path', $scratchRoot);
    $scratchPath = null;

    expect(function () use (&$scratchPath): void {
        app(JobScratch::class)->run(function (string $path) use (&$scratchPath): never {
            $scratchPath = $path;
            file_put_contents($path.'/partial.mp4', 'partial payload');

            throw new DomainException('encoding failed');
        });
    })->toThrow(DomainException::class, 'encoding failed');

    expect($scratchPath)->toBeString()
        ->and(is_dir($scratchPath))->toBeFalse();

    (new Filesystem)->deleteDirectory($scratchRoot);
});
