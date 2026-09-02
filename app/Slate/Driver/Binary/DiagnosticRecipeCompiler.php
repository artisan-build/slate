<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Driver\CompiledRecipe;

final readonly class DiagnosticRecipeCompiler
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filters,
    ) {}

    public function version(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option('-hide_banner'),
            CompiledArgument::option('-version'),
        ]);
    }

    public function filters(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option('-hide_banner'),
            CompiledArgument::option('-filters'),
        ]);
    }

    public function buildConfiguration(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option('-hide_banner'),
            CompiledArgument::option('-buildconf'),
        ]);
    }

    public function encoders(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option('-hide_banner'),
            CompiledArgument::option('-encoders'),
        ]);
    }

    public function luma(?string $fontPath): CompiledRecipe
    {
        $filters = array_filter([
            $fontPath === null ? null : $this->filters->compileDiagnosticDrawtext($fontPath)->value(),
            'signalstats',
            'metadata=print:key=lavfi.signalstats.YAVG',
        ]);

        return $this->ffmpegRecipe([
            CompiledArgument::option('-hide_banner'),
            CompiledArgument::option('-loglevel'),
            CompiledArgument::literal(CompiledLiteral::Info),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::Lavfi),
            CompiledArgument::option('-i'),
            CompiledArgument::lavfiInput(LavfiInput::BlackDiagnosticFrame),
            ...CompiledArgument::filter(
                CompiledFilterType::Video,
                CompiledFilterGraph::fromCompiler(implode(',', $filters)),
            ),
            CompiledArgument::option('-frames:v'),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::NullMuxer),
            CompiledArgument::literal(CompiledLiteral::StandardStream),
        ]);
    }

    /**
     * @param  list<CompiledArgument>  $arguments
     */
    private function ffmpegRecipe(array $arguments): CompiledRecipe
    {
        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }
}
