<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use RuntimeException;

final readonly class ScratchDirectory
{
    public function __construct(private string $path, private string $scratchRoot)
    {
        $resolvedPath = realpath($path);
        $resolvedRoot = realpath($scratchRoot);
        $configuredRoot = config('slate.storage.scratch_path');
        $resolvedConfiguredRoot = is_string($configuredRoot) ? realpath($configuredRoot) : false;

        if (! is_string($resolvedPath) || ! is_string($resolvedRoot) || $resolvedConfiguredRoot !== $resolvedRoot) {
            throw new RuntimeException('Job scratch directory must exist before paths are issued.');
        }

        if (dirname($resolvedPath) !== $resolvedRoot) {
            throw new RuntimeException('Job scratch directory must be directly beneath the configured scratch root.');
        }
    }

    public function file(string $filename): ScratchPath
    {
        return new ScratchPath($this, $filename);
    }

    public function path(): string
    {
        return $this->path;
    }
}
