<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

interface Operation
{
    public function name(): OperationName;
}
