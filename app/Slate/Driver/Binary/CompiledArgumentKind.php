<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledArgumentKind
{
    case AudioCodecOption;
    case AudioFilterOption;
    case AudioOutputPath;
    case CodecOption;
    case ComplexFilterOption;
    case FilterGraph;
    case Option;
    case Value;
    case VideoCodecOption;
    case VideoFilterOption;
    case VideoOutputPath;
}
