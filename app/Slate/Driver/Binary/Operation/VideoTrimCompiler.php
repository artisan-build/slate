<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\VideoTrim;
use InvalidArgumentException;

final readonly class VideoTrimCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoTrim;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoTrim) {
            throw new InvalidArgumentException('VideoTrimCompiler can compile only VideoTrim operations.');
        }

        $seek = $operation->startFrame === 0
            ? CompiledArgument::literal(CompiledLiteral::Zero)
            : CompiledArgument::seconds($operation->startSeconds());

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Seek),
            $seek,
            CompiledInput::ffmpeg($operation->input->path),
            CompiledArgument::option(CompiledOption::VideoFrames),
            CompiledArgument::unsignedInteger($operation->frameCount),
            CompiledOutput::copiedVideo($operation->output, $operation->durationSeconds()),
        ]);
    }
}
