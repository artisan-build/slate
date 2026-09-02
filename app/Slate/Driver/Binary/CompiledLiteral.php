<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledLiteral: string
{
    case Error = 'error';
    case Info = 'info';
    case Lavfi = 'lavfi';
    case One = '1';
    case NullMuxer = 'null';
    case StandardStream = '-';
}
