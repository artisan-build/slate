<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoThumbnail implements Operation
{
    public function __construct(
        public SlateVideo $input,
        public ScratchPath $output,
    ) {
        if (preg_match('/\.jpe?g$/i', $output->writablePath()) !== 1) {
            throw new InvalidArgumentException('Video thumbnails must use a JPEG output filename.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoThumbnail;
    }
}
