<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledArgumentKind;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\FfmpegLocator;
use InvalidArgumentException;
use LogicException;

final readonly class CompiledRecipe
{
    /**
     * @param  list<CompiledArgument|CompiledOutput>  $arguments
     */
    public function __construct(
        FfmpegLocator $locator,
        BinaryExecutable $executable,
        array $arguments,
        private ?string $workingDirectory = null,
    ) {
        foreach ($arguments as $argument) {
            if (! $argument instanceof CompiledArgument && ! $argument instanceof CompiledOutput) {
                throw new InvalidArgumentException('Compiled recipe arguments must be typed compiler output.');
            }
        }

        $argumentValues = [];

        foreach ($arguments as $argument) {
            array_push(
                $argumentValues,
                ...($argument instanceof CompiledOutput ? $argument->commandValues() : [$argument->value()]),
            );
        }
        $filterOptionKinds = [
            CompiledArgumentKind::AudioFilterOption,
            CompiledArgumentKind::ComplexFilterOption,
            CompiledArgumentKind::VideoFilterOption,
        ];

        foreach ($arguments as $index => $argument) {
            if ($argument instanceof CompiledOutput) {
                continue;
            }

            if (in_array($argument->kind(), $filterOptionKinds, true)
                && (! ($arguments[$index + 1] ?? null) instanceof CompiledArgument
                    || $arguments[$index + 1]->kind() !== CompiledArgumentKind::FilterGraph)) {
                throw new LogicException('A compiled filter option must be immediately paired with its graph.');
            }

            if ($argument->kind() === CompiledArgumentKind::FilterGraph
                && (! ($arguments[$index - 1] ?? null) instanceof CompiledArgument
                    || ! in_array($arguments[$index - 1]->kind(), $filterOptionKinds, true))) {
                throw new LogicException('A compiled filtergraph cannot exist outside its typed option pair.');
            }
        }

        $simpleVideoFilterCount = count(array_filter(
            $arguments,
            static fn (CompiledArgument|CompiledOutput $argument): bool => ($argument instanceof CompiledArgument
                && $argument->kind() === CompiledArgumentKind::VideoFilterOption)
                || ($argument instanceof CompiledOutput && $argument->videoFilterType() === CompiledFilterType::Video),
        ));
        $complexFilterCount = count(array_filter(
            $arguments,
            static fn (CompiledArgument|CompiledOutput $argument): bool => ($argument instanceof CompiledArgument
                && $argument->kind() === CompiledArgumentKind::ComplexFilterOption)
                || ($argument instanceof CompiledOutput && $argument->videoFilterType() === CompiledFilterType::Complex),
        ));

        if ($simpleVideoFilterCount > 1) {
            throw new LogicException('A compiled recipe cannot contain more than one -vf option.');
        }

        if ($simpleVideoFilterCount === 1 && $complexFilterCount > 0) {
            throw new LogicException('A compiled recipe cannot combine -vf with -filter_complex.');
        }

        $hasDiagnosticOption = array_any(
            $arguments,
            static fn (CompiledArgument|CompiledOutput $argument): bool => $argument instanceof CompiledArgument
                && $argument->kind() === CompiledArgumentKind::DiagnosticOption,
        );
        $hasFileOutput = array_any(
            $arguments,
            static fn (CompiledArgument|CompiledOutput $argument): bool => $argument instanceof CompiledOutput,
        );

        if ($hasDiagnosticOption && $hasFileOutput) {
            throw new LogicException('Diagnostic early-exit options cannot be combined with a file output.');
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
