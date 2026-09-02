<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

enum AudioChannelLayout: string
{
    case Mono = 'mono';
    case Stereo = 'stereo';
}
