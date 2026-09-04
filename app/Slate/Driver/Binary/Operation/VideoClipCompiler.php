<?php

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledH264OutputOptions;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\VideoClip;
use App\Slate\Recipe\Video\VideoClipPass;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use InvalidArgumentException;

final readonly class VideoClipCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoClip;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoClip) {
            throw new InvalidArgumentException('VideoClipCompiler can compile only VideoClip operations.');
        }

        return match ($operation->pass) {
            VideoClipPass::Coarse => $this->compileCoarse($operation),
            VideoClipPass::Precise => $this->compilePrecise($operation),
        };
    }

    private function compileCoarse(VideoClip $operation): CompiledRecipe
    {
        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Seek),
            $operation->startSeconds === 0.0
                ? CompiledArgument::literal(CompiledLiteral::Zero)
                : CompiledArgument::seconds($operation->startSeconds),
            CompiledInput::ffmpeg($operation->source),
            CompiledOutput::copiedVideo($operation->output, $operation->durationSeconds),
        ]);
    }

    private function compilePrecise(VideoClip $operation): CompiledRecipe
    {
        $filters = match ($operation->fit) {
            VideoFit::ContainPad => [
                "scale={$operation->width}:{$operation->height}:force_original_aspect_ratio=decrease",
                "pad={$operation->width}:{$operation->height}:(ow-iw)/2:(oh-ih)/2:color=".$this->color($operation->padColor),
            ],
        };

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledInput::ffmpeg($operation->source),
            CompiledArgument::option(CompiledOption::Seek),
            $operation->startSeconds === 0.0
                ? CompiledArgument::literal(CompiledLiteral::Zero)
                : CompiledArgument::seconds($operation->startSeconds),
            CompiledOutput::h264(
                $this->filterGraphCompiler->compileH264Video($filters),
                $operation->qualityTier,
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                CompiledH264OutputOptions::videoClip($operation->durationSeconds),
                $operation->output,
            ),
        ]);
    }

    private function color(VideoColor $color): string
    {
        return match ($color) {
            VideoColor::Black => 'black',
        };
    }
}
