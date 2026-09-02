<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use InvalidArgumentException;

final readonly class CompiledRecipe
{
    /**
     * @param  non-empty-list<string>  $command
     */
    public function __construct(
        private array $command,
        private ?string $workingDirectory = null,
    ) {
        if ($command === []) {
            throw new InvalidArgumentException('A compiled recipe command cannot be empty.');
        }

        foreach ($command as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Compiled recipe arguments must be non-empty strings without null bytes.');
            }
        }

        if ($workingDirectory !== null && ($workingDirectory === '' || str_contains($workingDirectory, "\0"))) {
            throw new InvalidArgumentException('A compiled recipe working directory must be a non-empty path without null bytes.');
        }
    }

    /**
     * @return non-empty-list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    public function workingDirectory(): ?string
    {
        return $this->workingDirectory;
    }
}
