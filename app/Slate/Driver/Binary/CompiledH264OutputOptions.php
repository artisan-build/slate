<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

/** Closed operation-specific mapping and timing options within one H.264 output unit. */
final readonly class CompiledH264OutputOptions
{
    /** @param  list<string>  $commandValues */
    private function __construct(private array $commandValues) {}

    public static function stillClip(float $durationSeconds): self
    {
        return new self([
            '-map',
            CompiledLiteral::VideoInputZero->value,
            '-map',
            CompiledLiteral::AudioInputOne->value,
            '-t',
            CompiledArgument::seconds($durationSeconds)->value(),
        ]);
    }

    public static function overlay(float $durationSeconds): self
    {
        return new self([
            '-map',
            CompiledLiteral::AudioInputZero->value,
            '-t',
            CompiledArgument::seconds($durationSeconds)->value(),
        ]);
    }

    public static function concat(): self
    {
        return new self(['-map', CompiledLiteral::AudioOutputLabel->value]);
    }

    /** @return list<string> */
    public function commandValues(): array
    {
        return $this->commandValues;
    }
}
