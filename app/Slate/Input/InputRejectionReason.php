<?php

declare(strict_types=1);

namespace App\Slate\Input;

enum InputRejectionReason: string
{
    case SourceTooLarge = 'source_too_large';
    case UnsupportedContainer = 'unsupported_container';
    case ReferencedMedia = 'referenced_media';
    case ProbeFailed = 'probe_failed';
    case DurationExceeded = 'duration_exceeded';
    case ResolutionExceeded = 'resolution_exceeded';
    case StreamCountExceeded = 'stream_count_exceeded';
    case DecodeCodecNotAllowed = 'decode_codec_not_allowed';
    case FrameRateExceeded = 'frame_rate_exceeded';
    case VideoStreamMissing = 'video_stream_missing';
    case ClipRangeInvalid = 'clip_range_invalid';
}
