<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use InvalidArgumentException;
use RuntimeException;

final readonly class ScratchPath
{
    private string $scratchDirectory;

    private string $path;

    public function __construct(ScratchDirectory $scratchDirectory, string $filename)
    {
        if ($filename === '' || basename($filename) !== $filename || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('Scratch filename must be one plain local filename.');
        }

        $this->scratchDirectory = $scratchDirectory->path();
        $this->path = $this->scratchDirectory.DIRECTORY_SEPARATOR.$filename;
    }

    public function readablePath(): string
    {
        $resolvedDirectory = realpath($this->scratchDirectory);
        $resolvedPath = realpath($this->path);

        if (
            ! is_string($resolvedDirectory)
            || ! is_string($resolvedPath)
            || dirname($resolvedPath) !== $resolvedDirectory
            || is_link($this->path)
            || ! is_file($resolvedPath)
        ) {
            throw new RuntimeException('Stage-out source must be a regular file in the current job scratch directory.');
        }

        return $resolvedPath;
    }

    public function writablePath(): string
    {
        $resolvedDirectory = realpath($this->scratchDirectory);

        if (! is_string($resolvedDirectory) || dirname($this->path) !== $resolvedDirectory || is_link($this->path)) {
            throw new RuntimeException('Stage-in destination must be a regular path in the current job scratch directory.');
        }

        if (file_exists($this->path)) {
            $resolvedPath = realpath($this->path);

            if (! is_string($resolvedPath) || dirname($resolvedPath) !== $resolvedDirectory || ! is_file($resolvedPath)) {
                throw new RuntimeException('Stage-in destination must be a regular path in the current job scratch directory.');
            }
        }

        return $this->path;
    }
}
