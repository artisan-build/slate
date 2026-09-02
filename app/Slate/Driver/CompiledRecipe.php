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

        if (! in_array(basename($command[0]), ['ffmpeg', 'ffprobe'], true)) {
            throw new InvalidArgumentException('A compiled recipe must execute the pinned ffmpeg or ffprobe binary.');
        }

        foreach ($command as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Compiled recipe arguments must be non-empty strings without null bytes.');
            }

            if (preg_match('/^(?:(?:async|cache|concat|crypto|data|file|https?|pipe):|subfile(?:,|:))/i', $argument) === 1) {
                throw new InvalidArgumentException('Compiled recipe arguments cannot use protocol handlers.');
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
