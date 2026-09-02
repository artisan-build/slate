<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;

/** A video output whose provenance is rooted in Slate's still-image assembler. */
final readonly class SlateVideo
{
    private function __construct(
        public ScratchPath $path,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public float $durationSeconds,
        public QualityTier $qualityTier,
    ) {}

    public static function fromStillClip(VideoStillClip $operation): self
    {
        return new self(
            $operation->output,
            $operation->width,
            $operation->height,
            $operation->framesPerSecond,
            $operation->durationSeconds,
            $operation->qualityTier,
        );
    }

    public static function fromConcat(VideoConcat $operation): self
    {
        return new self(
            $operation->output,
            $operation->width(),
            $operation->height(),
            $operation->framesPerSecond,
            $operation->outputDurationSeconds(),
            $operation->qualityTier,
        );
    }

    public static function fromOverlay(OverlayImage $operation): self
    {
        return new self(
            $operation->output,
            $operation->input->width,
            $operation->input->height,
            $operation->input->framesPerSecond,
            $operation->input->durationSeconds,
            $operation->input->qualityTier,
        );
    }
}
