<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
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
            CompiledOutput::mp3($operation->bitrate, $operation->output),
        ]);
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }
}
