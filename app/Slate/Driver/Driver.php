<?php

declare(strict_types=1);

namespace App\Slate\Driver;

interface Driver
{
    public function run(CompiledRecipe $recipe): DriverResult;
}
