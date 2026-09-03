<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;

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

    public static function h264(
        CompiledH264FilterGraph $filterGraph,
        QualityTier $tier,
        int $width,
        int $height,
        int $framesPerSecond,
        CompiledH264OutputOptions $outputOptions,
        ScratchPath $output,
    ): self {
        $filterType = $filterGraph->complex ? CompiledFilterType::Complex : CompiledFilterType::Video;
        $values = [$filterType->value, $filterGraph->filterGraph()->value()];

        if ($filterGraph->complex) {
            array_push($values, CompiledOption::Map->value, CompiledLiteral::VideoOutputLabel->value);
        }

        $values = [
            ...$values,
            ...H264OutputContract::argumentsFor($tier, $width, $height, $framesPerSecond),
            ...$outputOptions->commandValues(),
            $output->writablePath(),
        ];

        return new self($values, $filterType);
    }

    public static function mp3(
        AudioBitrate $bitrate,
        ScratchPath $output,
        ?AudioChannelLayout $channels = null,
    ): self {
        $channelValues = match ($channels) {
            AudioChannelLayout::Mono => ['-ac', '1'],
            AudioChannelLayout::Stereo => ['-ac', '2'],
            null => [],
        };

        return new self([
            '-c:a',
            CompiledLiteral::Libmp3lame->value,
            ...$channelValues,
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

    public static function frameMd5(): self
    {
        return new self([
            '-f',
            CompiledLiteral::FrameMd5->value,
            CompiledLiteral::StandardStream->value,
        ]);
    }

    public static function null(): self
    {
        return new self(['-f', CompiledLiteral::NullMuxer->value, CompiledLiteral::StandardStream->value]);
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
}
