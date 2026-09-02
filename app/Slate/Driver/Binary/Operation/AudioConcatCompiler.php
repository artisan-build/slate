<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\LavfiInput;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioConcat;
use App\Slate\Recipe\Audio\AudioConcatInput;
use App\Slate\Recipe\Audio\AudioConcatMode;
use App\Slate\Recipe\Operation;
use InvalidArgumentException;
use RuntimeException;

final readonly class AudioConcatCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof AudioConcat;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof AudioConcat) {
            throw new InvalidArgumentException('AudioConcatCompiler can compile only AudioConcat operations.');
        }

        return match ($operation->mode) {
            AudioConcatMode::Copy => $this->compileCopy($operation),
            AudioConcatMode::Reencode => $this->compileReencode($operation),
        };
    }

    private function compileCopy(AudioConcat $operation): CompiledRecipe
    {
        $inputPaths = array_map(
            static fn (AudioConcatInput $item): string => $item->input->readablePath(),
            $operation->items,
        );
        $manifestName = 'audio-concat-'.substr(hash('sha256', implode("\0", $inputPaths)), 0, 16).'.ffconcat';
        $manifest = $operation->output->sibling($manifestName);
        $manifestContents = "ffconcat version 1.0\n";

        foreach ($inputPaths as $inputPath) {
            $manifestContents .= "file '".$this->escapeManifestPath($inputPath)."'\n";
        }

        $bytesWritten = file_put_contents($manifest->writablePath(), $manifestContents, LOCK_EX);

        if ($bytesWritten !== strlen($manifestContents)) {
            throw new RuntimeException('Unable to write the compiler-owned audio concat manifest.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option('-y'),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::Concat),
            CompiledArgument::option('-safe'),
            CompiledArgument::literal(CompiledLiteral::Zero),
            CompiledArgument::option('-i'),
            CompiledArgument::existingLocalPath($manifest),
            CompiledArgument::option('-c:a'),
            CompiledArgument::literal(CompiledLiteral::Copy),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::Mp3),
            CompiledArgument::localPath($operation->output),
        ]);
    }

    private function compileReencode(AudioConcat $operation): CompiledRecipe
    {
        $arguments = [CompiledArgument::option('-y')];

        foreach ($operation->items as $item) {
            if ($item instanceof AudioConcatInput) {
                $arguments[] = CompiledArgument::option('-i');
                $arguments[] = CompiledArgument::existingLocalPath($item->input);

                continue;
            }

            $arguments[] = CompiledArgument::option('-f');
            $arguments[] = CompiledArgument::literal(CompiledLiteral::Lavfi);
            $arguments[] = CompiledArgument::option('-t');
            $arguments[] = CompiledArgument::seconds($item->durationSeconds);
            $arguments[] = CompiledArgument::option('-i');
            $arguments[] = CompiledArgument::lavfiInput(LavfiInput::Silence44100Mono);
        }

        $inputLabels = implode('', array_map(
            static fn (int $index): string => "[{$index}:a]",
            array_keys($operation->items),
        ));
        $filter = CompiledFilterGraph::fromCompiler(
            $inputLabels.'concat=n='.count($operation->items).':v=0:a=1[audio]',
        );

        $arguments = [
            ...$arguments,
            ...CompiledArgument::filter(CompiledFilterType::Complex, $filter),
            CompiledArgument::option('-map'),
            CompiledArgument::literal(CompiledLiteral::AudioOutputLabel),
            CompiledArgument::option('-c:a'),
            CompiledArgument::literal(CompiledLiteral::Libmp3lame),
            CompiledArgument::option('-b:a'),
            CompiledArgument::literal($this->bitrate($operation->bitrate)),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::Mp3),
            CompiledArgument::localPath($operation->output),
        ];

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, $arguments);
    }

    private function bitrate(AudioBitrate $bitrate): CompiledLiteral
    {
        return match ($bitrate) {
            AudioBitrate::Kbps128 => CompiledLiteral::Kbps128,
            AudioBitrate::Kbps192 => CompiledLiteral::Kbps192,
        };
    }

    private function escapeManifestPath(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }
}
