<?php

declare(strict_types=1);

namespace App\Slate\Run;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class RunRecipe implements Arrayable, JsonSerializable
{
    /** @var non-empty-list<RunRecipeOperation> */
    public array $operations;

    /** @param array<array-key, mixed> $operations */
    public function __construct(array $operations)
    {
        if ($operations === [] || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Run recipe operations must be a non-empty list.');
        }

        foreach ($operations as $operation) {
            if (! $operation instanceof RunRecipeOperation) {
                throw new InvalidArgumentException('Run recipes accept only typed operations.');
            }
        }

        $this->operations = $operations;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $runPrefix): self
    {
        $operations = $data['operations'] ?? null;

        if (array_keys($data) !== ['operations'] || ! is_array($operations) || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Run recipe payload must contain an operation list.');
        }

        return new self(array_map(
            static function (mixed $operation) use ($runPrefix): RunRecipeOperation {
                if (! is_array($operation)) {
                    throw new InvalidArgumentException('Run recipe operation payload must be an object.');
                }

                return match ($operation['operation'] ?? null) {
                    'video.still_clip' => RunStillClip::fromArray($operation, $runPrefix),
                    'video.clip' => RunVideoClip::fromArray($operation, $runPrefix),
                    default => throw new InvalidArgumentException('Run recipe contains an unsupported operation.'),
                };
            },
            $operations,
        ));
    }

    /** @throws JsonException */
    public static function fromJson(string $json, string $runPrefix): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Stored run recipe must decode to an object.');
        }

        return self::fromArray($data, $runPrefix);
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{operations: non-empty-list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static fn (RunRecipeOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }

    /** @return array{operations: non-empty-list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
