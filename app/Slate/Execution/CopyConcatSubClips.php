<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class CopyConcatSubClips implements PlannedOperation
{
    public VideoConcatMode $mode;

    /** @var non-empty-list<ObjectKey> */
    public array $inputs;

    /** @param array<array-key, mixed> $inputs */
    public function __construct(
        public int $index,
        array $inputs,
        private ObjectKey $output,
    ) {
        if ($index < 0 || count($inputs) < 2 || ! array_is_list($inputs)) {
            throw new InvalidArgumentException('A split-operation concat requires an operation index and at least two ordered inputs.');
        }

        foreach ($inputs as $input) {
            if (! $input instanceof ObjectKey) {
                throw new InvalidArgumentException('Split-operation concat inputs must be typed object keys.');
            }
        }

        $this->inputs = $inputs;
        $this->mode = VideoConcatMode::Copy;
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
