<?php

declare(strict_types=1);

namespace App\Slate\Run;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/** @implements Arrayable<string, list<array{operation: string, quality_tier: string, duration_microseconds: int, has_audio: bool}>> */
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
    public static function fromArray(array $data): self
    {
        $operations = $data['operations'] ?? null;

        if (! is_array($operations) || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Run recipe payload must contain an operation list.');
        }

        return new self(array_map(
            static function (mixed $operation): RunRecipeOperation {
                if (! is_array($operation)) {
                    throw new InvalidArgumentException('Run recipe operation payload must be an object.');
                }

                return RunRecipeOperation::fromArray($operation);
            },
            $operations,
        ));
    }

    /** @return array{operations: non-empty-list<array{operation: string, quality_tier: string, duration_microseconds: int, has_audio: bool}>} */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static fn (RunRecipeOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }

    /** @return array{operations: non-empty-list<array{operation: string, quality_tier: string, duration_microseconds: int, has_audio: bool}>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
