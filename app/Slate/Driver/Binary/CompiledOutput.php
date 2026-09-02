<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

/** An atomic ordered output scope containing its codec selection and destination path. */
final readonly class CompiledOutput
{
    /**
     * @param  non-empty-list<string>  $commandValues
     */
    private function __construct(
        private array $commandValues,
        private ?CompiledFilterType $videoFilterType = null,
    ) {}

    /**
     * @param  list<CompiledArgument>  $outputArguments
     */
    public static function h264(
        CompiledH264FilterGraph $filterGraph,
        QualityTier $tier,
        int $width,
        int $height,
        int $framesPerSecond,
        array $outputArguments,
        ScratchPath $output,
    ): self {
        foreach ($outputArguments as $argument) {
            if (! $argument instanceof CompiledArgument
                || ! in_array($argument->kind(), [CompiledArgumentKind::Option, CompiledArgumentKind::Value], true)) {
                throw new InvalidArgumentException('H.264 output options must be ordinary typed arguments.');
            }
        }

        $filterType = $filterGraph->complex ? CompiledFilterType::Complex : CompiledFilterType::Video;
        $values = [$filterType->value, $filterGraph->filterGraph()->value()];

        if ($filterGraph->complex) {
            array_push($values, CompiledOption::Map->value, CompiledLiteral::VideoOutputLabel->value);
        }

        $values = [
            ...$values,
            ...H264OutputContract::argumentsFor($tier, $width, $height, $framesPerSecond),
            ...array_map(static fn (CompiledArgument $argument): string => $argument->value(), $outputArguments),
            $output->writablePath(),
        ];

        return new self($values, $filterType);
    }

    /** @param  list<CompiledArgument>  $outputArguments */
    public static function mp3(AudioBitrate $bitrate, ScratchPath $output, array $outputArguments = []): self
    {
        $argumentValues = self::ordinaryArgumentValues($outputArguments);

        return new self([
            '-c:a',
            CompiledLiteral::Libmp3lame->value,
            ...$argumentValues,
            '-b:a',
            $bitrate->value,
            '-f',
            CompiledLiteral::Mp3->value,
            $output->writablePath(),
        ]);
    }

    public static function copiedMp3(ScratchPath $output): self
    {
        return new self([
            '-c:a',
            CompiledLiteral::Copy->value,
            '-f',
            CompiledLiteral::Mp3->value,
            $output->writablePath(),
        ]);
    }

    public static function copiedVideo(ScratchPath $output): self
    {
        return new self([
            '-c',
            CompiledLiteral::Copy->value,
            $output->writablePath(),
        ]);
    }

    public static function jpeg(ScratchPath $output): self
    {
        return new self([
            '-c:v',
            CompiledLiteral::Mjpeg->value,
            '-f',
            CompiledLiteral::Image2->value,
            $output->writablePath(),
        ]);
    }

    /** @return non-empty-list<string> */
    public function commandValues(): array
    {
        return $this->commandValues;
    }

    public function videoFilterType(): ?CompiledFilterType
    {
        return $this->videoFilterType;
    }

    /**
     * @param  list<CompiledArgument>  $arguments
     * @return list<string>
     */
    private static function ordinaryArgumentValues(array $arguments): array
    {
        foreach ($arguments as $argument) {
            if (! $argument instanceof CompiledArgument
                || ! in_array($argument->kind(), [CompiledArgumentKind::Option, CompiledArgumentKind::Value], true)) {
                throw new InvalidArgumentException('Output options must be ordinary typed arguments.');
            }
        }

        return array_map(static fn (CompiledArgument $argument): string => $argument->value(), $arguments);
    }
}
