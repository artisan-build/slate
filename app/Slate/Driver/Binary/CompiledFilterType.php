<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledFilterType: string
{
    case Audio = '-af';
    case Complex = '-filter_complex';
    case Video = '-vf';

    public function argumentKind(): CompiledArgumentKind
    {
        return match ($this) {
            self::Audio => CompiledArgumentKind::AudioFilterOption,
            self::Complex => CompiledArgumentKind::ComplexFilterOption,
            self::Video => CompiledArgumentKind::VideoFilterOption,
        };
    }
}
