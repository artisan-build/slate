<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoTransitionSegment implements Operation
{
    public function __construct(
        public SlateVideo $from,
        public SlateVideo $to,
        public int $frameCount,
        public QualityTier $qualityTier,
        public ScratchPath $output,
    ) {
        if ($from->width !== $to->width || $from->height !== $to->height) {
            throw new InvalidArgumentException('Transition segment inputs must have matching dimensions.');
        }

        if ($from->framesPerSecond !== $to->framesPerSecond) {
            throw new InvalidArgumentException('Transition segment inputs must have matching frame rates.');
        }

        if ($frameCount < 1 || $frameCount > $from->frameCount || $frameCount > $to->frameCount) {
            throw new InvalidArgumentException('Transition segment frame count must fit within both inputs.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoTransitionSegment;
    }

    public function framesPerSecond(): int
    {
        return $this->from->framesPerSecond;
    }

    public function durationSeconds(): float
    {
        return $this->frameCount / $this->framesPerSecond();
    }

    public function fromStartFrame(): int
    {
        return $this->from->frameCount - $this->frameCount;
    }

    public function fromStartSeconds(): float
    {
        return $this->fromStartFrame() / $this->framesPerSecond();
    }
}
