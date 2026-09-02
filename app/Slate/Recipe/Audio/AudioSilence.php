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
        if (! AudioDuration::isPositiveMicrosecondDuration($durationSeconds)) {
            throw new InvalidArgumentException('Silence duration must be between one microsecond and 24 hours at microsecond precision.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::AudioSilence;
    }
}
