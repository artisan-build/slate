<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use InvalidArgumentException;

final readonly class CompiledFilterGraph
{
    private function __construct(private string $value) {}

    public static function fromCompiler(string $value): self
    {
        if ($value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('A compiled filtergraph must be non-empty and contain no null bytes.');
        }

        if (preg_match('~[A-Za-z][A-Za-z0-9+.-]*://~', $value) === 1) {
            throw new InvalidArgumentException('A compiled filtergraph cannot contain a URL.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
