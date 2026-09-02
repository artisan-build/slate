<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\VideoThumbnail;
use InvalidArgumentException;

final readonly class VideoThumbnailCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoThumbnail;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoThumbnail) {
            throw new InvalidArgumentException('VideoThumbnailCompiler can compile only VideoThumbnail operations.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option('-y'),
            CompiledArgument::option('-ss'),
            CompiledArgument::literal(CompiledLiteral::Zero),
            CompiledArgument::option('-i'),
            CompiledArgument::existingLocalPath($operation->input->path),
            CompiledArgument::option('-frames:v'),
            CompiledArgument::literal(CompiledLiteral::One),
            ...CompiledArgument::jpegOutput($operation->output),
        ]);
    }
}
