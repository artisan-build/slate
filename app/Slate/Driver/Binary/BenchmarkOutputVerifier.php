<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Services\FfmpegDiagnostics;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Storage\ScratchPath;
use RuntimeException;

final readonly class BenchmarkOutputVerifier
{
    public function __construct(
        private Driver $driver,
        private FfmpegDiagnostics $diagnostics,
    ) {}

    public function compiledDrawtextCount(TimedWords $operation, RecipeCompiler $compiler): int
    {
        return array_sum(array_map(
            static fn (string $argument): int => substr_count($argument, 'drawtext='),
            $compiler->compile($operation)->command(),
        ));
    }

    public function captionRegionLuma(ScratchPath $video, float $seconds): float
    {
        return $this->luma($video, $seconds, 'crop=512:160:64:640,signalstats,metadata=print:key=lavfi.signalstats.YAVG');
    }

    public function frameLuma(ScratchPath $video, float $seconds): float
    {
        return $this->luma($video, $seconds, 'signalstats,metadata=print:key=lavfi.signalstats.YAVG');
    }

    /** @return non-empty-list<string> */
    public function frameHashes(ScratchPath $video): array
    {
        $recipe = new CompiledRecipe(FfmpegLocator::fromSystem(), BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::LogLevel),
            CompiledArgument::literal(CompiledLiteral::Error),
            CompiledInput::ffmpeg($video),
            CompiledArgument::option(CompiledOption::Map),
            CompiledArgument::literal(CompiledLiteral::VideoInputZero),
            CompiledOutput::frameMd5(),
        ]);
        $result = $this->driver->run($recipe);
        $hashes = [];

        if ($result->successful()) {
            foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
                if (preg_match('/,\s*([a-f0-9]{32})$/i', trim($line), $matches) === 1) {
                    $hashes[] = strtolower($matches[1]);
                }
            }
        }

        if ($hashes === []) {
            throw new RuntimeException('Unable to hash decoded video frames: '.$result->errorOutput);
        }

        return $hashes;
    }

    private function luma(ScratchPath $video, float $seconds, string $filterGraph): float
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
                    $filterGraph,
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
            throw new RuntimeException('Unable to measure the video frame luma: '.$result->errorOutput);
        }

        return $luma;
    }
}
