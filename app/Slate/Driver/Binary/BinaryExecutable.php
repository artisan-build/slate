<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

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
