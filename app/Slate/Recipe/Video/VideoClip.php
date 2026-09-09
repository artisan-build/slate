<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoClip implements Operation
{
    private function __construct(
        public ScratchPath $source,
        public ScratchPath $output,
        public float $startSeconds,
        public float $durationSeconds,
        public VideoClipPass $pass,
        public int $width = 1536,
        public int $height = 1024,
        public int $framesPerSecond = 24,
        public VideoFit $fit = VideoFit::ContainPad,
        public VideoColor $padColor = VideoColor::Black,
        public QualityTier $qualityTier = QualityTier::Delivery,
    ) {
        if ($startSeconds < 0 || ! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Video clip ranges require a non-negative start and positive duration.');
        }

        if (round($startSeconds, 6) !== $startSeconds) {
            throw new InvalidArgumentException('Video clip start must use at most microsecond precision.');
        }

        if (! in_array([$width, $height], [[1536, 1024], [1024, 1536]], true)
            || $framesPerSecond !== 24) {
            throw new InvalidArgumentException('Video clip output must match an audited resolution and 24 fps contract.');
        }
    }

    public static function coarse(
        ScratchPath $source,
        int $startMicroseconds,
        int $endMicroseconds,
        float $sourceDurationSeconds,
        ScratchPath $output,
        int $paddingSeconds = 5,
    ): self {
        if ($startMicroseconds < 0
            || $endMicroseconds <= $startMicroseconds
            || $paddingSeconds < 1
            || $endMicroseconds > (int) floor($sourceDurationSeconds * 1_000_000)) {
            throw new InvalidArgumentException('Coarse video clip range must fit within the source duration.');
        }

        $paddingMicroseconds = $paddingSeconds * 1_000_000;
        $coarseStart = max(0, $startMicroseconds - $paddingMicroseconds);
        $coarseEnd = min(
            (int) floor($sourceDurationSeconds * 1_000_000),
            $endMicroseconds + $paddingMicroseconds,
        );

        return new self(
            $source,
            $output,
            $coarseStart / 1_000_000,
            ($coarseEnd - $coarseStart) / 1_000_000,
            VideoClipPass::Coarse,
        );
    }

    public static function precise(
        ScratchPath $source,
        int $startMicroseconds,
        int $durationMicroseconds,
        ScratchPath $output,
        QualityTier $qualityTier = QualityTier::Delivery,
    ): self {
        return new self(
            $source,
            $output,
            $startMicroseconds / 1_000_000,
            $durationMicroseconds / 1_000_000,
            VideoClipPass::Precise,
            qualityTier: $qualityTier,
        );
    }

    public function name(): OperationName
    {
        return OperationName::VideoClip;
    }
}
