<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Services\FfmpegDiagnostics;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Storage\ScratchPath;
use RuntimeException;

final readonly class BenchmarkOutputVerifier
{
    public function __construct(
        private Driver $driver,
        private FfmpegDiagnostics $diagnostics,
    ) {}

    public function captionRegionLuma(ScratchPath $video, float $seconds): float
    {
        $recipe = new CompiledRecipe(FfmpegLocator::fromSystem(), BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::LogLevel),
            CompiledArgument::literal(CompiledLiteral::Info),
            CompiledArgument::option(CompiledOption::Seek),
            CompiledArgument::seconds($seconds),
            CompiledInput::ffmpeg($video),
            ...CompiledArgument::filter(
                CompiledFilterType::Video,
                CompiledFilterGraph::fromCompiler(
                    'crop=512:160:64:640,signalstats,metadata=print:key=lavfi.signalstats.YAVG',
                ),
            ),
            CompiledArgument::option(CompiledOption::VideoFrames),
            CompiledArgument::literal(CompiledLiteral::One),
            CompiledOutput::null(),
        ]);
        $result = $this->driver->run($recipe);
        $luma = $result->successful()
            ? $this->diagnostics->averageLuma($result->output.$result->errorOutput)
            : null;

        if ($luma === null) {
            throw new RuntimeException('Unable to measure the benchmark caption region: '.$result->errorOutput);
        }

        return $luma;
    }
}
