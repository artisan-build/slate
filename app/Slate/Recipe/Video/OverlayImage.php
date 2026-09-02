<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class OverlayImage implements Operation
{
    public function __construct(
        public SlateVideo $input,
        public ScratchPath $image,
        public ScratchPath $output,
        public int $x,
        public int $y,
        public OverlayFadeIn $fadeIn,
        public float $enableFromSeconds,
    ) {
        if ($x < 0 || $x >= $input->width || $y < 0 || $y >= $input->height) {
            throw new InvalidArgumentException('Overlay coordinates must begin within the video frame.');
        }

        if (! AudioDuration::isNonNegativeMicrosecondDuration($enableFromSeconds)
            || $enableFromSeconds > $input->durationSeconds) {
            throw new InvalidArgumentException('Overlay enable time must fall within the video duration.');
        }

        if ($fadeIn->startSeconds + $fadeIn->durationSeconds > $input->durationSeconds) {
            throw new InvalidArgumentException('Overlay fade must end within the video duration.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::OverlayImage;
    }

    public function video(): SlateVideo
    {
        return SlateVideo::fromOverlay($this);
    }
}
