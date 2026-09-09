<?php

declare(strict_types=1);

namespace App\Slate\Source;

use App\Slate\Http\WireEnum;

enum SourceApiErrorReason: string implements WireEnum
{
    case InvalidSource = 'invalid_source';
    case SourceNotFound = 'source_not_found';
}
