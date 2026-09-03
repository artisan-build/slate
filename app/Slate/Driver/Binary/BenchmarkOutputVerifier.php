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

    /**
     * Structural stream-copy proof for a compiled recipe: returns human-readable violations when the
     * recipe would re-encode its output instead of copying it.
     *
     * Decoded frame hashes are content-only and cannot distinguish a stream copy from a re-encode that
     * happens to reproduce the same pixels, so the recipe's own codec selection is asserted directly.
     * An empty list means the recipe both requests codec copy and selects no encoder.
     *
     * @return list<string>
     */
    public function streamCopyViolations(CompiledRecipe $recipe): array
    {
        $command = $recipe->command();
        $violations = [];

        if (in_array(H264OutputContract::encoder(), $command, true)) {
            $violations[] = sprintf(
                'recipe selects the %s encoder instead of stream copy',
                H264OutputContract::encoder(),
            );
        }

        if (! $this->requestsCodecCopy($command)) {
            $violations[] = 'recipe does not select codec copy for any output stream';
        }

        return $violations;
    }

    /** @param  non-empty-list<string>  $command */
    private function requestsCodecCopy(array $command): bool
    {
        foreach ($command as $index => $argument) {
            if (in_array($argument, ['-c', '-c:v', '-c:a'], true)
                && ($command[$index + 1] ?? null) === CompiledLiteral::Copy->value) {
                return true;
            }
        }

        return false;
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

    /** @return non-empty-list<int> */
    public function audioSamples(ScratchPath $video): array
    {
        $recipe = new CompiledRecipe(FfmpegLocator::fromSystem(), BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::HideBanner),
            CompiledArgument::option(CompiledOption::LogLevel),
            CompiledArgument::literal(CompiledLiteral::Error),
            CompiledInput::ffmpeg($video),
            CompiledOutput::pcmS16leMono44100(),
        ]);
        $result = $this->driver->run($recipe);

        if ($result->failed() || $result->output === '' || strlen($result->output) % 2 !== 0) {
            throw new RuntimeException('Unable to decode video audio samples: '.$result->errorOutput);
        }

        $unsignedSamples = unpack('v*', $result->output);

        if (! is_array($unsignedSamples) || $unsignedSamples === []) {
            throw new RuntimeException('Decoded video audio contained no samples.');
        }

        return array_values(array_map(
            static fn (int $sample): int => $sample >= 32768 ? $sample - 65536 : $sample,
            $unsignedSamples,
        ));
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
