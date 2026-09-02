<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

enum AudioConcatMode: string
{
    case Copy = 'copy';
    case Reencode = 'reencode';
}
