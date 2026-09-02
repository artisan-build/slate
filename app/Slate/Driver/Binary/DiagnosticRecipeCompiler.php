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
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::Version),
        ]);
    }

    public function filters(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::Filters),
        ]);
    }

    public function buildConfiguration(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::BuildConfiguration),
        ]);
    }

    public function encoders(): CompiledRecipe
    {
        return $this->ffmpegRecipe([
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::Encoders),
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
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::LogLevel),
            CompiledArgument::literal(CompiledLiteral::Info),
            CompiledArgument::option(CompiledOption::Format),
            CompiledArgument::literal(CompiledLiteral::Lavfi),
            CompiledArgument::option(CompiledOption::Input),
            CompiledArgument::lavfiInput(LavfiInput::BlackDiagnosticFrame),
            ...CompiledArgument::filter(
                CompiledFilterType::Video,
                CompiledFilterGraph::fromCompiler(implode(',', $filters)),
            ),
            CompiledArgument::option(CompiledOption::VideoFrames),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledArgument::option(CompiledOption::Format),
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
