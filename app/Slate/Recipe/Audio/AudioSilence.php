<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class AudioSilence implements Operation
{
    public function __construct(
        public ScratchPath $output,
        public float $durationSeconds,
        public AudioSampleRate $sampleRate,
        public AudioChannelLayout $channels,
        public AudioFormat $format,
        public AudioBitrate $bitrate,
    ) {
        if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
            throw new InvalidArgumentException('Silence duration must be finite and greater than zero.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::AudioSilence;
    }
}
