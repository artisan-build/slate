<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final readonly class JobScratch
{
    public function __construct(private Filesystem $filesystem) {}

    /**
     * Runs work in local scratch that exists only for this job invocation.
     *
     * Scratch is never shared between queue hosts. Persist data needed by another job through
     * ObjectStorage::stageOut() and retrieve it there through ObjectStorage::stageIn().
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $work
     * @return TResult
     */
    public function run(Closure $work): mixed
    {
        $scratchRoot = rtrim((string) config('slate.storage.scratch_path'), DIRECTORY_SEPARATOR);
        $scratchPath = $scratchRoot.DIRECTORY_SEPARATOR.Str::uuid()->toString();

        $this->filesystem->ensureDirectoryExists($scratchPath, 0700);

        try {
            return $work($scratchPath);
        } finally {
            $this->filesystem->deleteDirectory($scratchPath);
        }
    }
}
