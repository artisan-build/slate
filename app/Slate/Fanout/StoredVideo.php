<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class StoredVideo
{
    public function __construct(
        public ObjectKey $objectKey,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public int $frameCount,
        public float $durationSeconds,
        public QualityTier $qualityTier,
        public ?int $keyframeIntervalFrames,
        public bool $hasAudio = true,
    ) {
        if ($width < 1 || $height < 1 || $framesPerSecond < 1 || $frameCount < 1 || $durationSeconds <= 0) {
            throw new InvalidArgumentException('Stored video metadata must contain positive media dimensions and durations.');
        }

        if ($keyframeIntervalFrames !== null && $keyframeIntervalFrames < 1) {
            throw new InvalidArgumentException('Stored video keyframe interval must be positive when known.');
        }
    }

    public static function fromRendered(SlateVideo $video, ObjectKey $objectKey): self
    {
        return new self(
            $objectKey,
            $video->width,
            $video->height,
            $video->framesPerSecond,
            $video->frameCount,
            $video->durationSeconds,
            $video->qualityTier,
            $video->keyframeIntervalFrames,
            $video->hasAudio,
        );
    }
}
