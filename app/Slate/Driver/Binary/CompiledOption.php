<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledOption: string
{
    case AudioBitrate = '-b:a';
    case AudioChannels = '-ac';
    case AudioCodec = '-c:a';
    case BuildConfiguration = '-buildconf';
    case Codec = '-c';
    case Encoders = '-encoders';
    case EntropyCoder = '-coder';
    case Format = '-f';
    case GroupOfPictures = '-g';
    case HideBanner = '-hide_banner';
    case Input = '-i';
    case InputFrameRate = '-framerate';
    case LogLevel = '-loglevel';
    case Loop = '-loop';
    case Map = '-map';
    case OutputFormat = '-of';
    case OutputFrameRate = '-r';
    case Overwrite = '-y';
    case PixelFormat = '-pix_fmt';
    case RateControlMode = '-rc_mode';
    case Safe = '-safe';
    case Seek = '-ss';
    case ShowFormat = '-show_format';
    case ShowStreams = '-show_streams';
    case Duration = '-t';
    case Verbosity = '-v';
    case Version = '-version';
    case VideoBitrate = '-b:v';
    case VideoCodec = '-c:v';
    case VideoFrames = '-frames:v';
    case VideoProfile = '-profile:v';
    case Filters = '-filters';

    public function argumentKind(): CompiledArgumentKind
    {
        return match ($this) {
            self::AudioCodec => CompiledArgumentKind::AudioCodecOption,
            self::Codec => CompiledArgumentKind::CodecOption,
            self::VideoCodec => CompiledArgumentKind::VideoCodecOption,
            default => CompiledArgumentKind::Option,
        };
    }
}
