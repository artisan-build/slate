<?php

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('issues only plain filenames inside the current job scratch directory', function (string $filename): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($filename): void {
        expect(fn () => $scratch->file($filename))->toThrow(InvalidArgumentException::class);
    });
})->with([
    'http wrapper' => 'http://127.0.0.1/secret.txt',
    'php wrapper' => 'php://memory',
    'absolute path' => '/tmp/arbitrary-write.txt',
    'parent traversal' => '../runtime/ffmpeg',
    'nested path' => 'nested/input.mp4',
    'null byte' => "input\0.mp4",
]);

test('rejects symlinks that escape scratch for reads and writes', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $outsidePath = storage_path('framework/testing/slate-outside.txt');
        file_put_contents($outsidePath, 'outside');
        $scratchPath = $scratch->file('linked.txt');
        symlink($outsidePath, $scratchPath->writablePath());

        try {
            expect(fn () => $scratchPath->readablePath())->toThrow(RuntimeException::class)
                ->and(fn () => $scratchPath->writablePath())->toThrow(RuntimeException::class);
        } finally {
            @unlink($outsidePath);
        }
    });
});
