<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use InvalidArgumentException;

final readonly class CompiledArgument
{
    private function __construct(private string $value) {}

    public static function option(string $option): self
    {
        if (preg_match('/^-[A-Za-z][A-Za-z0-9:_-]*$/', $option) !== 1) {
            throw new InvalidArgumentException('A compiled option must be one complete ffmpeg option.');
        }

        return new self($option);
    }

    public static function localPath(string $path): self
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('A compiled local path must be absolute and contain no null bytes.');
        }

        return new self($path);
    }

    public static function literal(CompiledLiteral $literal): self
    {
        return new self($literal->value);
    }

    public static function lavfiInput(LavfiInput $input): self
    {
        return new self($input->value);
    }

    public static function filterGraph(CompiledFilterGraph $filterGraph): self
    {
        return new self($filterGraph->value());
    }

    public function value(): string
    {
        return $this->value;
    }
}
