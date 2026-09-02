<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

/**
 * Selects ffmpeg or ffprobe from the supplied locator. The locator permits absolute runtime overrides
 * outside base_path() so tests and mounted runtime probes can provide their actual filesystem root.
 */
enum BinaryExecutable
{
    case Ffmpeg;
    case Ffprobe;

    public function path(FfmpegLocator $locator): string
    {
        return match ($this) {
            self::Ffmpeg => $locator->ffmpegPath(),
            self::Ffprobe => $locator->ffprobePath(),
        };
    }
}
