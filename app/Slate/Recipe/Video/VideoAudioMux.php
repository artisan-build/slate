<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoAudioMux implements Operation
{
    public function __construct(
        public SlateVideo $video,
        public ScratchPath $audio,
        public AudioBitrate $audioBitrate,
        public ScratchPath $output,
    ) {
        if ($video->hasAudio) {
            throw new InvalidArgumentException('Video audio mux requires a silent intermediate video.');
        }

        if ($audioBitrate !== AudioBitrate::Kbps192) {
            throw new InvalidArgumentException('Video audio mux must use the audited AAC bitrate.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoAudioMux;
    }
}
