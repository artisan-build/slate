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
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use InvalidArgumentException;

final readonly class VideoStillClipCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoStillClip;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoStillClip) {
            throw new InvalidArgumentException('VideoStillClipCompiler can compile only VideoStillClip operations.');
        }

        $workingWidth = $operation->width * $operation->supersample;
        $workingHeight = $operation->height * $operation->supersample;
        $frames = max(1, (int) round($operation->durationSeconds * $operation->framesPerSecond));
        $zoom = $this->zoomExpression($operation->motion, $frames);
        $filters = match ($operation->fit) {
            VideoFit::ContainPad => [
                "scale={$workingWidth}:{$workingHeight}:force_original_aspect_ratio=decrease",
                "pad={$workingWidth}:{$workingHeight}:(ow-iw)/2:(oh-ih)/2:color=".$this->color($operation->padColor),
                "zoompan=z={$zoom}:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):d={$frames}:s={$workingWidth}x{$workingHeight}:fps={$operation->framesPerSecond}",
            ],
        };

        if ($operation->supersample === 2) {
            $filters[] = "scale={$operation->width}:{$operation->height}";
        }

        if ($operation->fadeOut !== null) {
            $filters[] = 'fade=type=out:st='.$this->formatSeconds($operation->fadeOut->startSeconds)
                .':d='.$this->formatSeconds($operation->fadeOut->durationSeconds)
                .':color='.$this->color($operation->fadeOut->color);
        }

        $filterGraph = $this->filterGraphCompiler->compileH264Video($filters);
        $arguments = [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::InputFrameRate),
            CompiledArgument::unsignedInteger($operation->framesPerSecond),
            CompiledArgument::option(CompiledOption::Loop),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledInput::ffmpeg($operation->image),
            CompiledInput::ffmpeg($operation->audio),
            CompiledOutput::h264(
                $filterGraph,
                $operation->qualityTier,
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
                CompiledH264OutputOptions::stillClip($operation->durationSeconds),
                $operation->output,
            ),
        ];

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }

    private function zoomExpression(StillMotion|KenBurnsMotion $motion, int $frames): string
    {
        if (! $motion instanceof KenBurnsMotion) {
            return '1';
        }

        $zoomRatio = match ($motion->ease) {
            KenBurnsEase::Sine => $this->formatSeconds($motion->zoomPercent / 100),
        };

        return match ($motion->direction) {
            KenBurnsDirection::In => "1+{$zoomRatio}*(1-cos(PI*on/{$frames}))/2",
            KenBurnsDirection::Out => "1+{$zoomRatio}*(1+cos(PI*on/{$frames}))/2",
        };
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function color(VideoColor $color): string
    {
        return match ($color) {
            VideoColor::Black => 'black',
        };
    }
}
