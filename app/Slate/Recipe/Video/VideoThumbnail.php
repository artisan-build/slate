<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;

final readonly class VideoThumbnail implements Operation
{
    public function __construct(
        public SlateVideo $input,
        public ScratchPath $output,
    ) {}

    public function name(): OperationName
    {
        return OperationName::VideoThumbnail;
    }
}
