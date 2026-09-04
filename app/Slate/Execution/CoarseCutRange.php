<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class CoarseCutRange
{
    public function __construct(
        public int $operationIndex,
        public int $startMicroseconds,
        public int $endMicroseconds,
        public ObjectKey $output,
    ) {
        if ($operationIndex < 0 || $startMicroseconds < 0 || $endMicroseconds <= $startMicroseconds) {
            throw new InvalidArgumentException('A coarse cut requires a valid operation index and time range.');
        }
    }
}
