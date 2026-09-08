<?php

declare(strict_types=1);

namespace App\Models;

enum SourceStatus: string
{
    case Pending = 'pending';
    case Ingesting = 'ingesting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Rejected], true);
    }
}
