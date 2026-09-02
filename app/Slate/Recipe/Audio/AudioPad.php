<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class AudioPad implements Operation
{
    public function __construct(
        public ScratchPath $input,
        public ScratchPath $output,
        public float $leadSilenceSeconds,
        public float $trailSilenceSeconds,
        public AudioBitrate $bitrate,
    ) {
        if (! is_finite($leadSilenceSeconds) || $leadSilenceSeconds < 0) {
            throw new InvalidArgumentException('Lead silence must be a finite non-negative duration.');
        }

        if (! is_finite($trailSilenceSeconds) || $trailSilenceSeconds < 0) {
            throw new InvalidArgumentException('Trail silence must be a finite non-negative duration.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::AudioPad;
    }
}
