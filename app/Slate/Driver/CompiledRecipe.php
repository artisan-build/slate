<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\FfmpegLocator;
use InvalidArgumentException;

final readonly class CompiledRecipe
{
    /**
     * @param  list<CompiledArgument>  $arguments
     */
    public function __construct(
        FfmpegLocator $locator,
        BinaryExecutable $executable,
        array $arguments,
        private ?string $workingDirectory = null,
    ) {
        foreach ($arguments as $argument) {
            if (! $argument instanceof CompiledArgument) {
                throw new InvalidArgumentException('Compiled recipe arguments must be typed compiler output.');
            }
        }

        if ($workingDirectory !== null && (! str_starts_with($workingDirectory, '/') || str_contains($workingDirectory, "\0"))) {
            throw new InvalidArgumentException('A compiled recipe working directory must be an absolute path without null bytes.');
        }

        $this->command = [
            $executable->path($locator),
            ...array_map(static fn (CompiledArgument $argument): string => $argument->value(), $arguments),
        ];
    }

    /** @var non-empty-list<string> */
    private array $command;

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
