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
        if (! AudioDuration::isNonNegativeMillisecondDuration($leadSilenceSeconds)) {
            throw new InvalidArgumentException('Lead silence must be between zero and 24 hours at millisecond precision.');
        }

        if (! AudioDuration::isNonNegativeMicrosecondDuration($trailSilenceSeconds)) {
            throw new InvalidArgumentException('Trail silence must be between zero and 24 hours at microsecond precision.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::AudioPad;
    }
}
