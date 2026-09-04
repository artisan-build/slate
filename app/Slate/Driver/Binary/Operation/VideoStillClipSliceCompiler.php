<?php

declare(strict_types=1);

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
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoStillClipSlice;
use InvalidArgumentException;

final readonly class VideoStillClipSliceCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoStillClipSlice;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoStillClipSlice) {
            throw new InvalidArgumentException('VideoStillClipSliceCompiler can compile only VideoStillClipSlice operations.');
        }

        $workingWidth = $operation->width * $operation->supersample;
        $workingHeight = $operation->height * $operation->supersample;
        $frames = max(1, (int) round($operation->durationSeconds() * $operation->framesPerSecond));
        $filters = [
            "scale={$workingWidth}:{$workingHeight}:force_original_aspect_ratio=decrease",
            "pad={$workingWidth}:{$workingHeight}:(ow-iw)/2:(oh-ih)/2:color=".$this->color($operation->padColor),
            "zoompan=z=1:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):d={$frames}:s={$workingWidth}x{$workingHeight}:fps={$operation->framesPerSecond}",
        ];

        if ($operation->supersample === 2) {
            $filters[] = "scale={$operation->width}:{$operation->height}";
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::InputFrameRate),
            CompiledArgument::unsignedInteger($operation->framesPerSecond),
            CompiledArgument::option(CompiledOption::Loop),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledInput::ffmpeg($operation->image),
            CompiledOutput::silentH264(
                $this->filterGraphCompiler->compileH264Video($filters),
                $operation->qualityTier,
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                CompiledH264OutputOptions::silentStillClip($operation->durationSeconds()),
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
