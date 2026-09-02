<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use InvalidArgumentException;

final readonly class DriverResult
{
    public function __construct(
        public string $output = '',
        public string $errorOutput = '',
        public int $exitCode = 0,
    ) {
        if ($exitCode < 0) {
            throw new InvalidArgumentException('A driver exit code cannot be negative.');
        }
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}
