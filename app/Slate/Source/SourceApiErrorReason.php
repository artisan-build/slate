<?php

declare(strict_types=1);

namespace App\Slate\Source;

enum SourceApiErrorReason: string
{
    case InvalidSource = 'invalid_source';
    case SourceNotFound = 'source_not_found';
}
