<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClipSlice;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class RenderSilentSubClip implements PlannedOperation
{
    public function __construct(
        public int $index,
        public int $subIndex,
        public ObjectKey $imageInput,
        public TimeRange $range,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public VideoFit $fit,
        public VideoColor $padColor,
        public int $supersample,
        public QualityTier $qualityTier,
        private ObjectKey $output,
    ) {
        if ($index < 0 || $subIndex < 0) {
            throw new InvalidArgumentException('Operation and sub-clip indexes cannot be negative.');
        }
    }

    public function localOperation(ScratchPath $image, ScratchPath $output): VideoStillClipSlice
    {
        return new VideoStillClipSlice(
            $image,
            $output,
            $this->width,
            $this->height,
            $this->framesPerSecond,
            $this->range->durationMicroseconds,
            $this->fit,
            $this->padColor,
            $this->supersample,
            $this->qualityTier,
        );
    }

    public function operationIndex(): int
    {
        return $this->index;
    }

    public function outputKey(): ObjectKey
    {
        return $this->output;
    }
}
