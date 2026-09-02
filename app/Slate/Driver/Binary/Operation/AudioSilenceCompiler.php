<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\LavfiInput;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Operation;
use InvalidArgumentException;

final readonly class AudioSilenceCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof AudioSilence;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof AudioSilence) {
            throw new InvalidArgumentException('AudioSilenceCompiler can compile only AudioSilence operations.');
        }

        $silenceInput = match ([$operation->sampleRate, $operation->channels]) {
            [AudioSampleRate::Hz44100, AudioChannelLayout::Mono] => LavfiInput::Silence44100Mono,
            [AudioSampleRate::Hz44100, AudioChannelLayout::Stereo] => LavfiInput::Silence44100Stereo,
        };
        $channelCount = match ($operation->channels) {
            AudioChannelLayout::Mono => CompiledLiteral::One,
            AudioChannelLayout::Stereo => CompiledLiteral::StereoChannelCount,
        };
        $format = match ($operation->format) {
            AudioFormat::Mp3 => CompiledLiteral::Mp3,
        };

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option('-y'),
            CompiledArgument::option('-f'),
            CompiledArgument::literal(CompiledLiteral::Lavfi),
            CompiledArgument::option('-i'),
            CompiledArgument::lavfiInput($silenceInput),
            CompiledArgument::option('-t'),
            CompiledArgument::seconds($operation->durationSeconds),
            CompiledArgument::option('-c:a'),
            CompiledArgument::literal(CompiledLiteral::Libmp3lame),
            CompiledArgument::option('-ac'),
            CompiledArgument::literal($channelCount),
            CompiledArgument::option('-b:a'),
            CompiledArgument::literal($this->bitrate($operation->bitrate)),
            CompiledArgument::option('-f'),
            CompiledArgument::literal($format),
            CompiledArgument::localPath($operation->output),
        ]);
    }

    private function bitrate(AudioBitrate $bitrate): CompiledLiteral
    {
        return match ($bitrate) {
            AudioBitrate::Kbps128 => CompiledLiteral::Kbps128,
            AudioBitrate::Kbps192 => CompiledLiteral::Kbps192,
        };
    }
}
