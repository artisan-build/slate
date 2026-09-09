<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class RunStillClip implements RunRecipeOperation
{
    public function __construct(
        public ObjectKey $image,
        public ObjectKey $audio,
        public int $width,
        public int $height,
        public int $framesPerSecond,
        public int $durationMicroseconds,
        public VideoFit $fit,
        public VideoColor $padColor,
        public int $supersample,
        public QualityTier $qualityTier,
        public AudioBitrate $audioBitrate,
        public ?RunTimedCaptions $captions = null,
    ) {
        if (! in_array([$width, $height], [[1536, 1024], [1024, 1536]], true)) {
            throw new InvalidArgumentException('Run still clip dimensions must match an audited resolution.');
        }

        if ($framesPerSecond !== 24) {
            throw new InvalidArgumentException('Run still clip frame rate must match the audited 24 fps contract.');
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($durationMicroseconds / 1_000_000)) {
            throw new InvalidArgumentException('Run still clip duration must be positive microsecond-precision.');
        }

        if (! in_array($supersample, [1, 2], true) || $audioBitrate !== AudioBitrate::Kbps192) {
            throw new InvalidArgumentException('Run still clip output settings must match the audited contract.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $runPrefix): self
    {
        $expectedKeys = [
            'operation', 'image_object_key', 'audio_object_key', 'width', 'height', 'frames_per_second',
            'duration_microseconds', 'fit', 'pad_color', 'supersample', 'quality_tier', 'audio_bitrate',
        ];

        if (array_key_exists('captions', $data)) {
            $expectedKeys[] = 'captions';
        }

        $actualKeys = array_keys($data);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys
            || $data['operation'] !== OperationName::VideoStillClip->value
            || ! is_string($data['image_object_key'])
            || ! is_string($data['audio_object_key'])
            || ! is_int($data['width'])
            || ! is_int($data['height'])
            || ! is_int($data['frames_per_second'])
            || ! is_int($data['duration_microseconds'])
            || ! is_int($data['supersample'])) {
            throw new InvalidArgumentException('Run still clip payload does not match its complete typed schema.');
        }

        $fit = VideoFit::tryFrom(is_string($data['fit']) ? $data['fit'] : '');
        $padColor = VideoColor::tryFrom(is_string($data['pad_color']) ? $data['pad_color'] : '');
        $qualityTier = QualityTier::tryFrom(is_string($data['quality_tier']) ? $data['quality_tier'] : '');
        $audioBitrate = AudioBitrate::tryFrom(is_string($data['audio_bitrate']) ? $data['audio_bitrate'] : '');

        if ($fit === null || $padColor === null || $qualityTier === null || $audioBitrate === null) {
            throw new InvalidArgumentException('Run still clip payload contains an unknown typed value.');
        }

        return new self(
            RunRecipeObjectKeys::fromStored($runPrefix, $data['image_object_key']),
            RunRecipeObjectKeys::fromStored($runPrefix, $data['audio_object_key']),
            $data['width'],
            $data['height'],
            $data['frames_per_second'],
            $data['duration_microseconds'],
            $fit,
            $padColor,
            $data['supersample'],
            $qualityTier,
            $audioBitrate,
            self::captionsFromArray(
                $data,
                $runPrefix,
                $data['duration_microseconds'] / 1_000_000,
                $data['width'],
                $data['height'],
            ),
        );
    }

    public function operation(): OperationName
    {
        return OperationName::VideoStillClip;
    }

    public function qualityTier(): QualityTier
    {
        return $this->qualityTier;
    }

    public function durationMicroseconds(): int
    {
        return $this->durationMicroseconds;
    }

    public function audioSource(): ObjectKey
    {
        return $this->audio;
    }

    public function audioBitrate(): AudioBitrate
    {
        return $this->audioBitrate;
    }

    public function localOperation(ScratchPath $image, ScratchPath $audio, ScratchPath $output): VideoStillClip
    {
        return new VideoStillClip(
            $image,
            $audio,
            $output,
            $this->width,
            $this->height,
            $this->framesPerSecond,
            $this->durationMicroseconds / 1_000_000,
            $this->fit,
            $this->padColor,
            new StillMotion,
            $this->supersample,
            $this->qualityTier,
            $this->audioBitrate,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'operation' => $this->operation()->value,
            'image_object_key' => $this->image->value(),
            'audio_object_key' => $this->audio->value(),
            'width' => $this->width,
            'height' => $this->height,
            'frames_per_second' => $this->framesPerSecond,
            'duration_microseconds' => $this->durationMicroseconds,
            'fit' => $this->fit->value,
            'pad_color' => $this->padColor->value,
            'supersample' => $this->supersample,
            'quality_tier' => $this->qualityTier->value,
            'audio_bitrate' => $this->audioBitrate->value,
        ];

        if ($this->captions !== null) {
            $data['captions'] = $this->captions->toArray();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    private static function captionsFromArray(
        array $data,
        string $runPrefix,
        float $durationSeconds,
        int $width,
        int $height,
    ): ?RunTimedCaptions {
        $captions = $data['captions'] ?? null;

        if ($captions === null) {
            return null;
        }

        if (! is_array($captions)) {
            throw new InvalidArgumentException('Run captions payload must be an object.');
        }

        return RunTimedCaptions::fromStoredArray($captions, $runPrefix, $durationSeconds, $width, $height);
    }
}
