<?php

namespace App\Models;

enum IngestStatus: string
{
    case Expected = 'expected';
    case Uploading = 'uploading';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
