<?php

namespace App\Models;

use App\Slate\Http\WireEnum;

enum RunStatus: string implements WireEnum
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
