<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

/** Implementations carry only typed domain parameters; compiler expressions are derived from those values. */
interface Operation
{
    public function name(): OperationName;
}
