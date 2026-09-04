<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/** @implements Arrayable<string, int|string|bool> */
final readonly class RunRecipeOperation implements Arrayable, JsonSerializable
{
    public function __construct(
        public OperationName $operation,
        public QualityTier $qualityTier,
        public int $durationMicroseconds,
        public bool $hasAudio,
    ) {
        if ($durationMicroseconds < 1) {
            throw new InvalidArgumentException('Run recipe operation duration must be positive.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $operation = OperationName::tryFrom(is_string($data['operation'] ?? null) ? $data['operation'] : '');
        $qualityTier = QualityTier::tryFrom(is_string($data['quality_tier'] ?? null) ? $data['quality_tier'] : '');

        if ($operation === null || $qualityTier === null
            || ! is_int($data['duration_microseconds'] ?? null)
            || ! is_bool($data['has_audio'] ?? null)) {
            throw new InvalidArgumentException('Run recipe operation contains invalid typed values.');
        }

        return new self(
            $operation,
            $qualityTier,
            $data['duration_microseconds'],
            $data['has_audio'],
        );
    }

    /** @return array{operation: string, quality_tier: string, duration_microseconds: int, has_audio: bool} */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'quality_tier' => $this->qualityTier->value,
            'duration_microseconds' => $this->durationMicroseconds,
            'has_audio' => $this->hasAudio,
        ];
    }

    /** @return array{operation: string, quality_tier: string, duration_microseconds: int, has_audio: bool} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
