<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\QualityTier;
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

    public static function localPath(ScratchPath $path, CompiledOutputType $outputType): self
    {
        return new self($path->writablePath(), $outputType->argumentKind());
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

    /**
     * This is the only production construction path for H.264 output argv, so a normalized graph is
     * required rather than left to each operation compiler to remember.
     *
     * @return non-empty-list<self>
     */
    public static function h264VideoOutput(
        CompiledH264FilterGraph $filterGraph,
        QualityTier $tier,
        int $width,
        int $height,
        int $framesPerSecond,
    ): array {
        $arguments = self::filter(
            $filterGraph->complex ? CompiledFilterType::Complex : CompiledFilterType::Video,
            $filterGraph->filterGraph(),
        );

        if ($filterGraph->complex) {
            array_push(
                $arguments,
                self::option(CompiledOption::Map),
                self::literal(CompiledLiteral::VideoOutputLabel),
            );
        }

        foreach (H264OutputContract::argumentsFor($tier, $width, $height, $framesPerSecond) as $index => $argument) {
            $arguments[] = $index % 2 === 0
                ? self::option(CompiledOption::from($argument))
                : new self($argument);
        }

        return $arguments;
    }

    /** @return non-empty-list<self> */
    public static function jpegOutput(ScratchPath $output): array
    {
        return [
            self::option(CompiledOption::VideoCodec),
            self::literal(CompiledLiteral::Mjpeg),
            self::option(CompiledOption::Format),
            self::literal(CompiledLiteral::Image2),
            self::localPath($output, CompiledOutputType::Video),
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
