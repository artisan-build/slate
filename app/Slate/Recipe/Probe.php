<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

use App\Slate\Storage\ScratchPath;

final readonly class Probe implements Operation
{
    public function __construct(public ScratchPath $input) {}

    public function name(): OperationName
    {
        return OperationName::Probe;
    }
}
