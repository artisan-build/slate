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
        return new CompiledRecipe([$this->locator->ffmpegPath(), '-hide_banner', '-version']);
    }

    public function filters(): CompiledRecipe
    {
        return new CompiledRecipe([$this->locator->ffmpegPath(), '-hide_banner', '-filters']);
    }

    public function buildConfiguration(): CompiledRecipe
    {
        return new CompiledRecipe([$this->locator->ffmpegPath(), '-hide_banner', '-buildconf']);
    }

    public function encoders(): CompiledRecipe
    {
        return new CompiledRecipe([$this->locator->ffmpegPath(), '-hide_banner', '-encoders']);
    }

    public function luma(?string $fontPath): CompiledRecipe
    {
        $filters = array_filter([
            $fontPath === null ? null : $this->filters->compileDiagnosticDrawtext($fontPath),
            'signalstats',
            'metadata=print:key=lavfi.signalstats.YAVG',
        ]);

        return new CompiledRecipe([
            $this->locator->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'info',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=640x200:d=1',
            '-vf',
            implode(',', $filters),
            '-frames:v',
            '1',
            '-f',
            'null',
            '-',
        ]);
    }
}
