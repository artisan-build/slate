<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class RunVideoClip implements RunRecipeOperation
{
    public function __construct(
        public ObjectKey $source,
        public float $start,
        public float $end,
        public QualityTier $qualityTier = QualityTier::Delivery,
    ) {
        if (! AudioDuration::isNonNegativeMicrosecondDuration($start)
            || ! AudioDuration::isPositiveMicrosecondDuration($end)
            || $end <= $start
            || ! AudioDuration::isPositiveMicrosecondDuration($end - $start)) {
            throw new InvalidArgumentException('Run video clip requires an increasing non-negative time range.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $runPrefix): self
    {
        $expectedKeys = ['operation', 'source', 'start', 'end'];

        if (array_key_exists('quality_tier', $data)) {
            $expectedKeys[] = 'quality_tier';
        }
        $actualKeys = array_keys($data);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys
            || $data['operation'] !== OperationName::VideoClip->value
            || ! is_string($data['source'])
            || (! is_int($data['start']) && ! is_float($data['start']))
            || (! is_int($data['end']) && ! is_float($data['end']))) {
            throw new InvalidArgumentException('Run video clip payload does not match its complete typed schema.');
        }

        $qualityTier = QualityTier::tryFrom(is_string($data['quality_tier'] ?? null) ? $data['quality_tier'] : QualityTier::Delivery->value);

        if ($qualityTier === null) {
            throw new InvalidArgumentException('Run video clip payload contains an unknown quality tier.');
        }

        return new self(
            RunRecipeObjectKeys::fromStored($runPrefix, $data['source']),
            (float) $data['start'],
            (float) $data['end'],
            $qualityTier,
        );
    }

    public function operation(): OperationName
    {
        return OperationName::VideoClip;
    }

    public function qualityTier(): QualityTier
    {
        return $this->qualityTier;
    }

    public function durationMicroseconds(): int
    {
        return $this->endMicroseconds() - $this->startMicroseconds();
    }

    public function startMicroseconds(): int
    {
        return (int) round($this->start * 1_000_000);
    }

    public function endMicroseconds(): int
    {
        return (int) round($this->end * 1_000_000);
    }

    public function audioSource(): ObjectKey
    {
        return $this->source;
    }

    public function audioBitrate(): AudioBitrate
    {
        return AudioBitrate::Kbps192;
    }

    /** @return array{operation: string, source: string, start: float, end: float, quality_tier: string} */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation()->value,
            'source' => $this->source->value(),
            'start' => $this->start,
            'end' => $this->end,
            'quality_tier' => $this->qualityTier->value,
        ];
    }

    /** @return array{operation: string, source: string, start: float, end: float, quality_tier: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
