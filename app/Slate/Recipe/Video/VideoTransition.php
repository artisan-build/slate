<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Http\WireEnum;

enum VideoTransition: string implements WireEnum
{
    case Fade = 'fade';
}
