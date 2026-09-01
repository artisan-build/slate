<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

enum QualityTier: string
{
    case Intermediate = 'intermediate';
    case Delivery = 'delivery';
}
