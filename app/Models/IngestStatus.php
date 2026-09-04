<?php

namespace App\Models;

enum IngestStatus: string
{
    case Expected = 'expected';
    case Uploading = 'uploading';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
