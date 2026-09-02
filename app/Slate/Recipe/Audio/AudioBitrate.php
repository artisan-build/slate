<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

enum AudioBitrate: string
{
    case Kbps128 = '128k';
    case Kbps192 = '192k';
}
