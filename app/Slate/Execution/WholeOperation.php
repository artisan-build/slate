<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Run\RunRecipeOperation;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class WholeOperation implements PlannedOperation
{
    public function __construct(
        public int $index,
        public RunRecipeOperation $recipeOperation,
        private ObjectKey $output,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException('Operation index cannot be negative.');
        }
    }

    public function operationIndex(): int
    {
        return $this->index;
    }

    public function outputKey(): ObjectKey
    {
        return $this->output;
    }
}
