<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

use App\Slate\Http\WireEnum;

enum QualityTier: string implements WireEnum
{
    case Intermediate = 'intermediate';
    case Delivery = 'delivery';
}
