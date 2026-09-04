<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoStillClipSlice implements Operation
{
    public function __construct(
        public ScratchPath $image,
        public ScratchPath $output,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public int $durationMicroseconds,
        public VideoFit $fit,
        public VideoColor $padColor,
        public int $supersample,
        public QualityTier $qualityTier,
    ) {
        if (! in_array([$width, $height], [[1536, 1024], [1024, 1536]], true)
            || $framesPerSecond !== 24
            || ! AudioDuration::isPositiveMicrosecondDuration($this->durationSeconds())
            || ! in_array($supersample, [1, 2], true)) {
            throw new InvalidArgumentException('Silent still-clip slice must match the audited video contract.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoStillClipSlice;
    }

    public function durationSeconds(): float
    {
        return $this->durationMicroseconds / 1_000_000;
    }
}
