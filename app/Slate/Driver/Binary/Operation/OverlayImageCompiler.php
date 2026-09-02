<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\OverlayImage;
use InvalidArgumentException;

final readonly class OverlayImageCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof OverlayImage;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof OverlayImage) {
            throw new InvalidArgumentException('OverlayImageCompiler can compile only OverlayImage operations.');
        }

        $fadeStart = $this->formatSeconds($operation->fadeIn->startSeconds);
        $fadeDuration = $this->formatSeconds($operation->fadeIn->durationSeconds);
        $enableFrom = $this->formatSeconds($operation->enableFromSeconds);
        $filterGraph = $this->filterGraphCompiler->compileH264ComplexVideo(
            ["[1:v]format=rgba,fade=type=in:st={$fadeStart}:d={$fadeDuration}:alpha=1[badge]"],
            "[0:v][badge]overlay=x={$operation->x}:y={$operation->y}:format=auto:enable=gte(t\\,{$enableFrom})",
        );
        $arguments = [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Input),
            CompiledArgument::existingLocalPath($operation->input->path),
            CompiledArgument::option(CompiledOption::InputFrameRate),
            CompiledArgument::unsignedInteger($operation->input->framesPerSecond),
            CompiledArgument::option(CompiledOption::Loop),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledArgument::option(CompiledOption::Duration),
            CompiledArgument::seconds($operation->input->durationSeconds),
            CompiledArgument::option(CompiledOption::Input),
            CompiledArgument::existingLocalPath($operation->image),
            CompiledOutput::h264(
                $filterGraph,
                $operation->input->qualityTier,
                $operation->input->width,
                $operation->input->height,
                $operation->input->framesPerSecond,
                [
                    CompiledArgument::option(CompiledOption::Map),
                    CompiledArgument::literal(CompiledLiteral::AudioInputZero),
                    CompiledArgument::option(CompiledOption::Duration),
                    CompiledArgument::seconds($operation->input->durationSeconds),
                ],
                $operation->output,
            ),
        ];

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }
}
