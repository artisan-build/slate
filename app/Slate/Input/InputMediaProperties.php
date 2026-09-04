<?php

declare(strict_types=1);

namespace App\Slate\Input;

final readonly class InputMediaProperties
{
    public function __construct(
        public float $durationSeconds,
        public int $width,
        public int $height,
        public float $framesPerSecond,
        public int $streamCount,
        public bool $hasAudio,
    ) {}
}
