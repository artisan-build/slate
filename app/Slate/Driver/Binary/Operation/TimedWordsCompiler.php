<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledH264OutputOptions;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Operation;
use InvalidArgumentException;

final readonly class TimedWordsCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof TimedWords;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof TimedWords) {
            throw new InvalidArgumentException('TimedWordsCompiler can compile only TimedWords operations.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledInput::ffmpeg($operation->input->path),
            CompiledOutput::h264(
                $this->filterGraphCompiler->compileTimedWords($operation),
                $operation->input->qualityTier,
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                CompiledH264OutputOptions::captions($operation->input->durationSeconds),
                $operation->output,
            ),
        ]);
    }
}
