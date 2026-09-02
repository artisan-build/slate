<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;

interface CompilesOperation
{
    /** Implementations must match the concrete operation with instanceof, never by operation name. */
    public function supports(Operation $operation): bool;

    public function compile(Operation $operation): CompiledRecipe;
}
