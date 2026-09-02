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
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use InvalidArgumentException;
use RuntimeException;

final readonly class VideoConcatCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoConcat;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoConcat) {
            throw new InvalidArgumentException('VideoConcatCompiler can compile only VideoConcat operations.');
        }

        return match ($operation->mode) {
            VideoConcatMode::Copy => $this->compileCopy($operation),
            VideoConcatMode::Xfade => $this->compileXfade($operation),
        };
    }

    private function compileCopy(VideoConcat $operation): CompiledRecipe
    {
        $manifest = $operation->output->sibling('video-concat-'.substr(hash(
            'sha256',
            implode("\0", array_map(static fn (SlateVideo $input): string => $input->path->readablePath(), $operation->inputs)),
        ), 0, 16).'.ffconcat');
        $manifestContents = "ffconcat version 1.0\n";

        foreach ($operation->inputs as $input) {
            $manifestContents .= "file '".$this->escapeManifestPath($input->path->readablePath())."'\n";
        }

        if (file_put_contents($manifest->writablePath(), $manifestContents, LOCK_EX) !== strlen($manifestContents)) {
            throw new RuntimeException('Unable to write the compiler-owned video concat manifest.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Format),
            CompiledArgument::literal(CompiledLiteral::Concat),
            CompiledArgument::option(CompiledOption::Safe),
            CompiledArgument::literal(CompiledLiteral::Zero),
            CompiledArgument::option(CompiledOption::Input),
            CompiledArgument::existingLocalPath($manifest),
            CompiledOutput::copiedVideo($operation->output),
        ]);
    }

    private function compileXfade(VideoConcat $operation): CompiledRecipe
    {
        $arguments = [CompiledArgument::option(CompiledOption::Overwrite)];

        foreach ($operation->inputs as $input) {
            $arguments[] = CompiledArgument::option(CompiledOption::Input);
            $arguments[] = CompiledArgument::existingLocalPath($input->path);
        }

        [$videoChains, $terminalVideoChain] = $this->videoChains($operation);
        $audioChains = $operation->audioCrossfade
            ? $this->crossfadeAudioChains($operation)
            : $this->hardCutAudioChains($operation);
        $filterGraph = $this->filterGraphCompiler->compileH264ComplexVideo(
            [...$videoChains, ...$audioChains],
            $terminalVideoChain,
        );
        $arguments = [
            ...$arguments,
            CompiledOutput::h264(
                $filterGraph,
                $operation->qualityTier,
                $operation->width(),
                $operation->height(),
                $operation->framesPerSecond,
                [
                    CompiledArgument::option(CompiledOption::Map),
                    CompiledArgument::literal(CompiledLiteral::AudioOutputLabel),
                ],
                $operation->output,
            ),
        ];

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }

    /**
     * @return array{list<string>, string}
     */
    private function videoChains(VideoConcat $operation): array
    {
        $chains = [];
        $previousLabel = '[0:v]';
        $composedDuration = $operation->inputs[0]->durationSeconds;
        $lastIndex = count($operation->inputs) - 1;
        $terminal = '';

        for ($index = 1; $index <= $lastIndex; $index++) {
            $offset = $composedDuration - $operation->transitionDurationSeconds;
            $chain = $previousLabel."[{$index}:v]xfade=transition=fade:duration="
                .$this->formatSeconds($operation->transitionDurationSeconds)
                .':offset='.$this->formatSeconds($offset);

            if ($index === $lastIndex) {
                $terminal = $chain;
            } else {
                $outputLabel = "[xfade{$index}]";
                $chains[] = $chain.$outputLabel;
                $previousLabel = $outputLabel;
            }

            $composedDuration += $operation->inputs[$index]->durationSeconds - $operation->transitionDurationSeconds;
        }

        return [$chains, $terminal];
    }

    /** @return list<string> */
    private function crossfadeAudioChains(VideoConcat $operation): array
    {
        $chains = [];
        $previousLabel = '[0:a]';
        $lastIndex = count($operation->inputs) - 1;

        for ($index = 1; $index <= $lastIndex; $index++) {
            $outputLabel = $index === $lastIndex ? '[audio]' : "[acrossfade{$index}]";
            $chains[] = $previousLabel."[{$index}:a]acrossfade=d="
                .$this->formatSeconds($operation->transitionDurationSeconds).$outputLabel;
            $previousLabel = $outputLabel;
        }

        return $chains;
    }

    /** @return list<string> */
    private function hardCutAudioChains(VideoConcat $operation): array
    {
        $chains = [];
        $inputLabels = '';
        $lastIndex = count($operation->inputs) - 1;

        foreach ($operation->inputs as $index => $input) {
            $outputLabel = "[hardcut{$index}]";
            $trim = $index === $lastIndex
                ? ''
                : 'atrim=end='.$this->formatSeconds($input->durationSeconds - $operation->transitionDurationSeconds).',';
            $chains[] = "[{$index}:a]{$trim}asetpts=PTS-STARTPTS{$outputLabel}";
            $inputLabels .= $outputLabel;
        }

        $chains[] = $inputLabels.'concat=n='.count($operation->inputs).':v=0:a=1[audio]';

        return $chains;
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function escapeManifestPath(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }
}
