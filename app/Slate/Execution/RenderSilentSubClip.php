<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Run\RunRecipeOperation;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class RenderSilentSubClip implements PlannedOperation
{
    public function __construct(
        public int $index,
        public int $subIndex,
        public RunRecipeOperation $recipeOperation,
        public TimeRange $range,
        private ObjectKey $output,
    ) {
        if ($index < 0 || $subIndex < 0) {
            throw new InvalidArgumentException('Operation and sub-clip indexes cannot be negative.');
        }
    }

    public function includesAudio(): bool
    {
        return false;
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
