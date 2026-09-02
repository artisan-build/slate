<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Filesystem\Filesystem;

test('removes per-job scratch after successful work', function (): void {
    $scratchRoot = storage_path('framework/testing/slate-job-scratch-success');
    config()->set('slate.storage.scratch_path', $scratchRoot);
    $scratchPath = null;

    $result = resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (&$scratchPath, $scratchRoot): string {
        $scratchPath = $scratch->path();

        expect(is_dir($scratchPath))->toBeTrue()
            ->and(str_starts_with($scratchPath, $scratchRoot.DIRECTORY_SEPARATOR))->toBeTrue();

        file_put_contents($scratch->file('output.mp4')->writablePath(), 'encoded payload');

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
        resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (&$scratchPath): never {
            $scratchPath = $scratch->path();
            file_put_contents($scratch->file('partial.mp4')->writablePath(), 'partial payload');

            throw new DomainException('encoding failed');
        });
    })->toThrow(DomainException::class, 'encoding failed');

    expect($scratchPath)->toBeString()
        ->and(is_dir($scratchPath))->toBeFalse();

    (new Filesystem)->deleteDirectory($scratchRoot);
});

test('fails before creating scratch when its configuration is missing or outside storage', function (mixed $configuredPath): void {
    config()->set('slate.storage.scratch_path', $configuredPath);

    expect(fn () => resolve(JobScratch::class)->run(fn (): null => null))
        ->toThrow(RuntimeException::class);
})->with([
    'missing' => null,
    'empty' => '',
    'filesystem root' => DIRECTORY_SEPARATOR,
    'outside application storage' => sys_get_temp_dir().'/slate-jobs',
]);

test('reports cleanup failure instead of silently returning success', function (): void {
    $scratchRoot = storage_path('framework/testing/slate-job-scratch-cleanup-failure');
    config()->set('slate.storage.scratch_path', $scratchRoot);
    $filesystem = Mockery::mock(Filesystem::class)->makePartial();
    $filesystem->shouldReceive('deleteDirectory')->once()->andReturnFalse();

    try {
        expect(fn () => (new JobScratch($filesystem))->run(fn (): string => 'complete'))
            ->toThrow(RuntimeException::class, 'Unable to remove job scratch directory');
    } finally {
        (new Filesystem)->deleteDirectory($scratchRoot);
    }
});
