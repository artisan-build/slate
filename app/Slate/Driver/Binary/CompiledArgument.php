<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Storage\ScratchPath;
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

    public static function localPath(ScratchPath $path): self
    {
        return new self($path->writablePath());
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
