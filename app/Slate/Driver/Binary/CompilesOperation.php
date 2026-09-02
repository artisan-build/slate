<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;

interface CompilesOperation
{
    public function supports(Operation $operation): bool;

    public function compile(Operation $operation): CompiledRecipe;
}
