<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class JobScratch
{
    public function __construct(private Filesystem $filesystem) {}

    /**
     * Runs work in local scratch that exists only for this job invocation.
     *
     * The callback receives paths confined to this invocation. Cleanup is guaranteed when PHP can
     * unwind normal return or an exception; process termination can still leave orphaned scratch.
     * Nothing in this class enforces bucket transfer between queued jobs, and FfmpegBenchmark still
     * passes local chunk paths between jobs pending PR 3.
     *
     * @template TResult
     *
     * @param  Closure(ScratchDirectory): TResult  $work
     * @return TResult
     */
    public function run(Closure $work): mixed
    {
        $scratchRoot = $this->scratchRoot();
        $scratchPath = $scratchRoot.DIRECTORY_SEPARATOR.Str::uuid()->toString();

        $this->filesystem->ensureDirectoryExists($scratchPath, 0700);
        $failure = null;

        try {
            return $work(new ScratchDirectory($scratchPath, $scratchRoot));
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            if (! $this->filesystem->deleteDirectory($scratchPath)) {
                throw new RuntimeException("Unable to remove job scratch directory: {$scratchPath}", previous: $failure);
            }
        }
    }

    private function scratchRoot(): string
    {
        $configuredRoot = config('slate.storage.scratch_path');
        $storageRoot = realpath(storage_path());

        if (! is_string($configuredRoot) || $configuredRoot === '' || ! is_string($storageRoot)) {
            throw new RuntimeException('Slate job scratch path is not configured.');
        }

        $scratchRoot = rtrim($configuredRoot, DIRECTORY_SEPARATOR);

        if (! str_starts_with($scratchRoot, $storageRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Slate job scratch path must be inside the application storage directory.');
        }

        $existingAncestor = $scratchRoot;

        while (! file_exists($existingAncestor) && dirname($existingAncestor) !== $existingAncestor) {
            $existingAncestor = dirname($existingAncestor);
        }

        $resolvedAncestor = realpath($existingAncestor);

        if (
            ! is_string($resolvedAncestor)
            || ($resolvedAncestor !== $storageRoot && ! str_starts_with($resolvedAncestor, $storageRoot.DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Slate job scratch path must resolve inside the application storage directory.');
        }

        $this->filesystem->ensureDirectoryExists($scratchRoot, 0700);
        $resolvedRoot = realpath($scratchRoot);

        if (! is_string($resolvedRoot) || ! str_starts_with($resolvedRoot, $storageRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Slate job scratch path must resolve inside the application storage directory.');
        }

        return $resolvedRoot;
    }
}
