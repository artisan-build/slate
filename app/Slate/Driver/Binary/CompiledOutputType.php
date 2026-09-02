<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledOutputType
{
    case Audio;
    case Video;

    public function argumentKind(): CompiledArgumentKind
    {
        return match ($this) {
            self::Audio => CompiledArgumentKind::AudioOutputPath,
            self::Video => CompiledArgumentKind::VideoOutputPath,
        };
    }
}
