<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\H264OutputContract;
use InvalidArgumentException;
use LogicException;

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

        $argumentValues = array_map(static fn (CompiledArgument $argument): string => $argument->value(), $arguments);
        $simpleVideoFilterCount = count(array_filter(
            $argumentValues,
            static fn (string $argument): bool => $argument === '-vf',
        ));

        if ($simpleVideoFilterCount > 1) {
            throw new LogicException('A compiled recipe cannot contain more than one -vf option.');
        }

        if ($simpleVideoFilterCount === 1 && in_array('-filter_complex', $argumentValues, true)) {
            throw new LogicException('A compiled recipe cannot combine -vf with -filter_complex.');
        }

        if (in_array(H264OutputContract::encoder(), $argumentValues, true)) {
            $filterOptions = array_values(array_filter(
                $argumentValues,
                static fn (string $argument): bool => in_array($argument, ['-vf', '-filter_complex'], true),
            ));

            if (count($filterOptions) !== 1) {
                throw new LogicException('Every H.264 recipe must contain exactly one normalized video filtergraph.');
            }

            $filterIndex = array_search($filterOptions[0], $argumentValues, true);
            $filterGraph = is_int($filterIndex) ? ($argumentValues[$filterIndex + 1] ?? '') : '';

            if (! str_contains($filterGraph, H264OutputContract::videoNormalizationFilter())) {
                throw new LogicException('Every H.264 recipe filtergraph must contain video normalization.');
            }
        }

        if ($workingDirectory !== null && (! str_starts_with($workingDirectory, '/') || str_contains($workingDirectory, "\0"))) {
            throw new InvalidArgumentException('A compiled recipe working directory must be an absolute path without null bytes.');
        }

        $this->command = [
            $executable->path($locator),
            ...$argumentValues,
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
