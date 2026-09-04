<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledVideoOrigin
{
    case SlateEncoded;
    case SourceDerived;
}
