<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum CompiledOption: string
{
    case AudioBitrate = '-b:a';
    case AudioChannels = '-ac';
    case BuildConfiguration = '-buildconf';
    case Encoders = '-encoders';
    case EntropyCoder = '-coder';
    case Format = '-f';
    case GroupOfPictures = '-g';
    case HideBanner = '-hide_banner';
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
    case VideoFrames = '-frames:v';
    case VideoProfile = '-profile:v';
    case Filters = '-filters';

    public function argumentKind(): CompiledArgumentKind
    {
        return match ($this) {
            self::BuildConfiguration, self::Encoders, self::Filters, self::Version => CompiledArgumentKind::DiagnosticOption,
            default => CompiledArgumentKind::Option,
        };
    }

    public function takesValue(): bool
    {
        return match ($this) {
            self::BuildConfiguration,
            self::Encoders,
            self::Filters,
            self::HideBanner,
            self::Overwrite,
            self::ShowFormat,
            self::ShowStreams,
            self::Version => false,
            default => true,
        };
    }
}
