<?php

namespace App\Slate\Execution;

use App\Slate\Storage\ObjectKey;

interface PlannedOperation
{
    public function operationIndex(): int;

    public function outputKey(): ObjectKey;
}
