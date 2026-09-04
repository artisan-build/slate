<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

enum OperationName: string
{
    case Probe = 'probe';
    case AudioSilence = 'audio.silence';
    case AudioConcat = 'audio.concat';
    case AudioPad = 'audio.pad';
    case VideoStillClip = 'video.still_clip';
    case VideoStillClipSlice = 'video.still_clip_slice';
    case VideoAudioMux = 'video.audio_mux';
    case VideoTrim = 'video.trim';
    case VideoTransitionSegment = 'video.transition_segment';
    case VideoConcat = 'video.concat';
    case OverlayImage = 'overlay.image';
    case CaptionsTimedWords = 'captions.timed_words';
    case VideoThumbnail = 'video.thumbnail';
}
