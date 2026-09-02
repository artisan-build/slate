<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledLiteral: string
{
    case AudioOutputLabel = '[audio]';
    case AudioInputZero = '0:a:0';
    case AudioInputOne = '1:a:0';
    case Concat = 'concat';
    case Copy = 'copy';
    case Error = 'error';
    case Info = 'info';
    case Json = 'json';
    case Kbps128 = '128k';
    case Kbps192 = '192k';
    case Lavfi = 'lavfi';
    case Libmp3lame = 'libmp3lame';
    case Mp3 = 'mp3';
    case One = '1';
    case NullMuxer = 'null';
    case StandardStream = '-';
    case StereoChannelCount = '2';
    case VideoInputZero = '0:v:0';
    case VideoOutputLabel = '[video]';
    case Zero = '0';
}
