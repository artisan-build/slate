<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoTrim implements Operation
{
    public function __construct(
        public SlateVideo $input,
        public int $startFrame,
        public int $frameCount,
        public ScratchPath $output,
    ) {
        if ($startFrame < 0) {
            throw new InvalidArgumentException('Video trim start frame cannot be negative.');
        }

        if ($frameCount < 1) {
            throw new InvalidArgumentException('Video trim must contain at least one frame.');
        }

        if ($startFrame + $frameCount > $input->frameCount) {
            throw new InvalidArgumentException('Video trim range must fit within the input frame count.');
        }

        if ($startFrame !== 0
            && ($input->keyframeIntervalFrames === null
                || $startFrame % $input->keyframeIntervalFrames !== 0)) {
            throw new InvalidArgumentException('Video trim must begin on a guaranteed keyframe boundary.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoTrim;
    }

    public function startSeconds(): float
    {
        return $this->startFrame / $this->input->framesPerSecond;
    }

    public function durationSeconds(): float
    {
        return $this->frameCount / $this->input->framesPerSecond;
    }
}
