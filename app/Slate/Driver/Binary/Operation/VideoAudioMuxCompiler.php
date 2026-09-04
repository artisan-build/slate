<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\VideoAudioMux;
use InvalidArgumentException;

final readonly class VideoAudioMuxCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoAudioMux;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoAudioMux) {
            throw new InvalidArgumentException('VideoAudioMuxCompiler can compile only VideoAudioMux operations.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledInput::ffmpeg($operation->video->path),
            CompiledInput::ffmpeg($operation->audio),
            CompiledOutput::muxedVideo(
                $operation->audioBitrate,
                $operation->video->durationSeconds,
                $operation->output,
            ),
        ]);
    }
}
