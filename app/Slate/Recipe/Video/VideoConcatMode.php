<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

enum VideoConcatMode: string
{
    case Copy = 'copy';
    case Xfade = 'xfade';
}
