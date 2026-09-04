<?php

namespace App\Models;

enum IngestMode: string
{
    case Source = 'source';
    case Direct = 'direct';
}
