<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use LogicException;

/** An atomic ordered output scope containing its codec selection and destination path. */
final readonly class CompiledOutput
{
    private const int MaximumOutputBytes = 16 * 1024 * 1024 * 1024;

    private const int MaximumOutputDurationSeconds = 86_400;

    /** @var non-empty-list<string> */
    private array $commandValues;

    /** @param non-empty-list<string> $commandValues */
    private function __construct(
        array $commandValues,
        private ?CompiledFilterType $videoFilterType = null,
        private ?CompiledInput $copiedVideoInput = null,
        private ?int $copiedVideoInputIndex = null,
        bool $consumerDeliverable = false,
    ) {
        if (($copiedVideoInput === null) !== ($copiedVideoInputIndex === null)
            || ($copiedVideoInputIndex !== null && $copiedVideoInputIndex < 0)) {
            throw new LogicException('A copied video output requires a non-negative bound input index.');
        }

        if ($copiedVideoInput !== null && $copiedVideoInput->videoOrigin() === null) {
            throw new LogicException('A copied video output requires typed input provenance.');
        }

        if ($consumerDeliverable
            && $copiedVideoInput?->videoOrigin() === CompiledVideoOrigin::SourceDerived) {
            throw new LogicException('A consumer deliverable cannot stream-copy source-derived video bytes.');
        }

        $this->commandValues = [
            ...self::hardeningCommandValues(),
            ...$commandValues,
        ];
    }

    /** @return list<string> */
    public static function hardeningCommandValues(): array
    {
        return [
            CompiledOption::MapMetadata->value,
            CompiledLiteral::NegativeOne->value,
            CompiledOption::Duration->value,
            (string) self::MaximumOutputDurationSeconds,
            CompiledOption::MaximumFileSize->value,
            (string) self::MaximumOutputBytes,
        ];
    }

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

    public static function silentH264(
        CompiledH264FilterGraph $filterGraph,
        QualityTier $tier,
        int $width,
        int $height,
        int $framesPerSecond,
        CompiledH264OutputOptions $outputOptions,
        ScratchPath $output,
    ): self {
        $filterType = $filterGraph->complex ? CompiledFilterType::Complex : CompiledFilterType::Video;
        $values = [
            $filterType->value,
            $filterGraph->filterGraph()->value(),
            ...H264OutputContract::videoArgumentsFor($tier, $width, $height, $framesPerSecond),
            ...$outputOptions->commandValues(),
            $output->writablePath(),
        ];

        return new self($values, $filterType);
    }

    public static function muxedVideo(
        CompiledInput $videoInput,
        int $videoInputIndex,
        AudioBitrate $audioBitrate,
        float $durationSeconds,
        ScratchPath $output,
    ): self {
        return new self([
            '-map',
            self::videoStream($videoInputIndex),
            '-map',
            CompiledLiteral::AudioInputOne->value,
            '-c:v',
            CompiledLiteral::Copy->value,
            '-c:a',
            H264OutputContract::audioEncoder(),
            '-b:a',
            $audioBitrate->value,
            '-t',
            CompiledArgument::seconds($durationSeconds)->value(),
            $output->writablePath(),
        ], copiedVideoInput: $videoInput, copiedVideoInputIndex: $videoInputIndex, consumerDeliverable: true);
    }

    public static function mp3(
        AudioBitrate $bitrate,
        ScratchPath $output,
        ?AudioChannelLayout $channels = null,
        ?float $durationSeconds = null,
    ): self {
        $channelValues = match ($channels) {
            AudioChannelLayout::Mono => ['-ac', '1'],
            AudioChannelLayout::Stereo => ['-ac', '2'],
            null => [],
        };

        return new self([
            ...($durationSeconds === null ? [] : [
                CompiledOption::Duration->value,
                CompiledArgument::seconds($durationSeconds)->value(),
            ]),
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

    public static function copiedVideo(
        CompiledInput $videoInput,
        int $videoInputIndex,
        ScratchPath $output,
        ?float $durationSeconds = null,
    ): self {
        return new self([
            ...($durationSeconds === null ? [] : [
                CompiledOption::Duration->value,
                CompiledArgument::seconds($durationSeconds)->value(),
            ]),
            '-map',
            self::videoStream($videoInputIndex),
            '-map',
            self::optionalAudioStream($videoInputIndex),
            '-c',
            CompiledLiteral::Copy->value,
            $output->writablePath(),
        ], copiedVideoInput: $videoInput, copiedVideoInputIndex: $videoInputIndex, consumerDeliverable: true);
    }

    public static function copiedIntermediateVideo(
        CompiledInput $videoInput,
        int $videoInputIndex,
        ScratchPath $output,
        ?float $durationSeconds = null,
    ): self {
        return new self([
            ...($durationSeconds === null ? [] : [
                CompiledOption::Duration->value,
                CompiledArgument::seconds($durationSeconds)->value(),
            ]),
            '-map',
            self::videoStream($videoInputIndex),
            '-map',
            self::optionalAudioStream($videoInputIndex),
            '-c',
            CompiledLiteral::Copy->value,
            $output->writablePath(),
        ], copiedVideoInput: $videoInput, copiedVideoInputIndex: $videoInputIndex);
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

    public static function pcmS16leMono44100(): self
    {
        return new self([
            '-map',
            CompiledLiteral::AudioInputZero->value,
            '-f',
            's16le',
            '-acodec',
            CompiledLiteral::PcmS16le->value,
            '-ac',
            CompiledLiteral::One->value,
            '-ar',
            '44100',
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

    public function copiedVideoInput(): ?CompiledInput
    {
        return $this->copiedVideoInput;
    }

    public function copiedVideoInputIndex(): ?int
    {
        return $this->copiedVideoInputIndex;
    }

    private static function videoStream(int $inputIndex): string
    {
        return $inputIndex.':v:0';
    }

    private static function optionalAudioStream(int $inputIndex): string
    {
        return $inputIndex.':a?';
    }
}
