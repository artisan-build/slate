<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledArgumentKind;
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
        $filterOptionKinds = [
            CompiledArgumentKind::AudioFilterOption,
            CompiledArgumentKind::ComplexFilterOption,
            CompiledArgumentKind::VideoFilterOption,
        ];

        foreach ($arguments as $index => $argument) {
            if (in_array($argument->kind(), $filterOptionKinds, true)
                && ($arguments[$index + 1] ?? null)?->kind() !== CompiledArgumentKind::FilterGraph) {
                throw new LogicException('A compiled filter option must be immediately paired with its graph.');
            }

            if ($argument->kind() === CompiledArgumentKind::FilterGraph
                && ! in_array(($arguments[$index - 1] ?? null)?->kind(), $filterOptionKinds, true)) {
                throw new LogicException('A compiled filtergraph cannot exist outside its typed option pair.');
            }
        }

        $simpleVideoFilterCount = count(array_filter(
            $arguments,
            static fn (CompiledArgument $argument): bool => $argument->kind() === CompiledArgumentKind::VideoFilterOption,
        ));
        $complexFilterCount = count(array_filter(
            $arguments,
            static fn (CompiledArgument $argument): bool => $argument->kind() === CompiledArgumentKind::ComplexFilterOption,
        ));

        if ($simpleVideoFilterCount > 1) {
            throw new LogicException('A compiled recipe cannot contain more than one -vf option.');
        }

        if ($simpleVideoFilterCount === 1 && $complexFilterCount > 0) {
            throw new LogicException('A compiled recipe cannot combine -vf with -filter_complex.');
        }

        $hasAudioOutput = array_any(
            $arguments,
            static fn (CompiledArgument $argument): bool => $argument->kind() === CompiledArgumentKind::AudioOutputPath,
        );
        $hasVideoOutput = array_any(
            $arguments,
            static fn (CompiledArgument $argument): bool => $argument->kind() === CompiledArgumentKind::VideoOutputPath,
        );
        $hasAudioCodec = array_any($arguments, static fn (CompiledArgument $argument): bool => in_array(
            $argument->kind(),
            [CompiledArgumentKind::AudioCodecOption, CompiledArgumentKind::CodecOption],
            true,
        ));
        $hasVideoCodec = array_any($arguments, static fn (CompiledArgument $argument): bool => in_array(
            $argument->kind(),
            [CompiledArgumentKind::VideoCodecOption, CompiledArgumentKind::CodecOption],
            true,
        ));

        if ($executable === BinaryExecutable::Ffmpeg
            && (($hasAudioOutput && ! $hasAudioCodec) || ($hasVideoOutput && ! $hasVideoCodec))) {
            throw new LogicException('Every ffmpeg file output must select its media codec explicitly.');
        }

        if (in_array(H264OutputContract::encoder(), $argumentValues, true)) {
            $videoFilterOptions = array_filter(
                $arguments,
                static fn (CompiledArgument $argument): bool => in_array($argument->kind(), [
                    CompiledArgumentKind::ComplexFilterOption,
                    CompiledArgumentKind::VideoFilterOption,
                ], true),
            );

            if (count($videoFilterOptions) !== 1) {
                throw new LogicException('Every H.264 recipe must contain exactly one normalized video filtergraph.');
            }

            $filterIndex = array_key_first($videoFilterOptions);
            $filterGraph = is_int($filterIndex) ? ($argumentValues[$filterIndex + 1] ?? '') : '';
            $normalizationSuffix = H264OutputContract::videoNormalizationFilter();

            if (($arguments[$filterIndex]->kind() ?? null) === CompiledArgumentKind::ComplexFilterOption) {
                $normalizationSuffix .= '[video]';
            }

            if (! str_ends_with($filterGraph, $normalizationSuffix)) {
                throw new LogicException('Every H.264 recipe filtergraph must end with video normalization.');
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
