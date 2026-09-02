<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledArgumentKind
{
    case AudioFilterOption;
    case ComplexFilterOption;
    case FilterGraph;
    case Option;
    case Value;
    case VideoFilterOption;
    case WritablePath;
}
