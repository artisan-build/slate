<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Storage\RunObjectKeys;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FanoutRunId
{
    private function __construct(private string $value) {}

    public static function generate(): self
    {
        return new self(Str::uuid()->toString());
    }

    public static function fromString(string $value): self
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('Fan-out run ID must be a UUID.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function prefix(): string
    {
        return RunObjectKeys::prefix($this->value);
    }
}
