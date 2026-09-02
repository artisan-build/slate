<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\StillMotion;
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
        $filters = [
            "scale={$workingWidth}:{$workingHeight}:force_original_aspect_ratio=decrease",
            "pad={$workingWidth}:{$workingHeight}:(ow-iw)/2:(oh-ih)/2:color={$operation->padColor->value}",
            "zoompan=z={$zoom}:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):d={$frames}:s={$workingWidth}x{$workingHeight}:fps={$operation->framesPerSecond}",
        ];

        if ($operation->supersample === 2) {
            $filters[] = "scale={$operation->width}:{$operation->height}";
        }

        if ($operation->fadeOut !== null) {
            $filters[] = 'fade=type=out:st='.$this->formatSeconds($operation->fadeOut->startSeconds)
                .':d='.$this->formatSeconds($operation->fadeOut->durationSeconds)
                .':color='.$operation->fadeOut->color->value;
        }

        $filterGraph = $this->filterGraphCompiler->compileH264Video($filters);
        $arguments = [
            CompiledArgument::option('-y'),
            CompiledArgument::option('-framerate'),
            CompiledArgument::unsignedInteger($operation->framesPerSecond),
            CompiledArgument::option('-loop'),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledArgument::option('-i'),
            CompiledArgument::existingLocalPath($operation->image),
            CompiledArgument::option('-i'),
            CompiledArgument::existingLocalPath($operation->audio),
            ...CompiledArgument::h264VideoOutput(
                $filterGraph,
                $operation->qualityTier,
                $operation->width,
                $operation->height,
                $operation->framesPerSecond,
            ),
            CompiledArgument::option('-map'),
            CompiledArgument::literal(CompiledLiteral::VideoInputZero),
            CompiledArgument::option('-map'),
            CompiledArgument::literal(CompiledLiteral::AudioInputOne),
            CompiledArgument::option('-t'),
            CompiledArgument::seconds($operation->durationSeconds),
            CompiledArgument::localPath($operation->output),
        ];

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }

    private function zoomExpression(StillMotion|KenBurnsMotion $motion, int $frames): string
    {
        if (! $motion instanceof KenBurnsMotion) {
            return '1';
        }

        $zoomRatio = $this->formatSeconds($motion->zoomPercent / 100);

        return match ($motion->direction) {
            KenBurnsDirection::In => "1+{$zoomRatio}*(1-cos(PI*on/{$frames}))/2",
            KenBurnsDirection::Out => "1+{$zoomRatio}*(1+cos(PI*on/{$frames}))/2",
        };
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }
}
