<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutputType;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioPad;
use App\Slate\Recipe\Operation;
use InvalidArgumentException;

final readonly class AudioPadCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof AudioPad;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof AudioPad) {
            throw new InvalidArgumentException('AudioPadCompiler can compile only AudioPad operations.');
        }

        $leadMilliseconds = (int) round($operation->leadSilenceSeconds * 1000);
        $trailSeconds = $this->formatSeconds($operation->trailSilenceSeconds);
        $filter = CompiledFilterGraph::fromCompiler(
            "adelay=delays={$leadMilliseconds}:all=1,apad=pad_dur={$trailSeconds}",
        );

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Input),
            CompiledArgument::existingLocalPath($operation->input),
            ...CompiledArgument::filter(CompiledFilterType::Audio, $filter),
            CompiledArgument::option(CompiledOption::AudioCodec),
            CompiledArgument::literal(CompiledLiteral::Libmp3lame),
            CompiledArgument::option(CompiledOption::AudioBitrate),
            CompiledArgument::literal($this->bitrate($operation->bitrate)),
            CompiledArgument::option(CompiledOption::Format),
            CompiledArgument::literal(CompiledLiteral::Mp3),
            CompiledArgument::localPath($operation->output, CompiledOutputType::Audio),
        ]);
    }

    private function bitrate(AudioBitrate $bitrate): CompiledLiteral
    {
        return match ($bitrate) {
            AudioBitrate::Kbps128 => CompiledLiteral::Kbps128,
            AudioBitrate::Kbps192 => CompiledLiteral::Kbps192,
        };
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }
}
