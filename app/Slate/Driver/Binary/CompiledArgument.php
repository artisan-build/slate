<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class CompiledArgument
{
    private function __construct(
        private string $value,
        private CompiledArgumentKind $kind = CompiledArgumentKind::Value,
    ) {}

    public static function option(CompiledOption $option): self
    {
        return new self($option->value, $option->argumentKind());
    }

    public static function existingLocalPath(ScratchPath $path): self
    {
        return new self($path->readablePath());
    }

    public static function seconds(float $seconds): self
    {
        if (! AudioDuration::isPositiveMicrosecondDuration($seconds)) {
            throw new InvalidArgumentException('Compiled seconds must be a positive microsecond-precision duration within the operational range.');
        }

        return new self(rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.'));
    }

    public static function unsignedInteger(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('A compiled unsigned integer cannot be negative.');
        }

        return new self((string) $value);
    }

    public static function literal(CompiledLiteral $literal): self
    {
        return new self($literal->value);
    }

    public static function lavfiInput(LavfiInput $input): self
    {
        return new self($input->value);
    }

    /** @return array{self, self} */
    public static function filter(CompiledFilterType $type, CompiledFilterGraph $filterGraph): array
    {
        return [
            new self($type->value, $type->argumentKind()),
            new self($filterGraph->value(), CompiledArgumentKind::FilterGraph),
        ];
    }

    public function value(): string
    {
        return $this->value;
    }

    public function kind(): CompiledArgumentKind
    {
        return $this->kind;
    }
}
