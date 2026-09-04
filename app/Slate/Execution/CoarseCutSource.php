<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class CoarseCutSource implements PlannedOperation
{
    /** @var non-empty-list<CoarseCutRange> */
    public array $ranges;

    /** @param array<array-key, mixed> $ranges */
    public function __construct(
        public ObjectKey $source,
        array $ranges,
    ) {
        if ($ranges === [] || ! array_is_list($ranges)) {
            throw new InvalidArgumentException('A coarse source cut requires an ordered range list.');
        }

        foreach ($ranges as $range) {
            if (! $range instanceof CoarseCutRange) {
                throw new InvalidArgumentException('A coarse source cut accepts only typed ranges.');
            }
        }

        $this->ranges = $ranges;
    }

    public function operationIndex(): int
    {
        return $this->ranges[0]->operationIndex;
    }

    public function outputKey(): ObjectKey
    {
        return $this->ranges[0]->output;
    }
}
