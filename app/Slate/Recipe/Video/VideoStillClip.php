<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoStillClip implements Operation
{
    public function __construct(
        public ScratchPath $image,
        public ScratchPath $audio,
        public ScratchPath $output,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public float $durationSeconds,
        public VideoFit $fit,
        public VideoColor $padColor,
        public StillMotion|KenBurnsMotion $motion,
        public int $supersample,
        public QualityTier $qualityTier,
        public AudioBitrate $audioBitrate,
        public ?VideoFadeOut $fadeOut = null,
    ) {
        if (! in_array([$width, $height], [[1536, 1024], [1024, 1536]], true)) {
            throw new InvalidArgumentException('Video dimensions must match an audited landscape or portrait resolution.');
        }

        if ($framesPerSecond !== 24) {
            throw new InvalidArgumentException('Video frame rate must match the audited 24 fps output contract.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Video duration must be a positive microsecond-precision duration.');
        }

        if (! in_array($supersample, [1, 2], true)) {
            throw new InvalidArgumentException('Video supersampling must be one or two.');
        }

        if ($audioBitrate !== AudioBitrate::Kbps192) {
            throw new InvalidArgumentException('H.264 video output requires the audited 192 kbps AAC audio contract.');
        }

        if ($fadeOut !== null && $fadeOut->startSeconds + $fadeOut->durationSeconds > $durationSeconds) {
            throw new InvalidArgumentException('Video fade must end within the clip duration.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoStillClip;
    }

    public function video(): SlateVideo
    {
        return SlateVideo::fromStillClip($this);
    }
}
