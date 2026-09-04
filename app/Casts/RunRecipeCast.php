<?php

namespace App\Casts;

use App\Slate\Run\RunRecipe;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<RunRecipe, RunRecipe> */
final class RunRecipeCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): RunRecipe
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Stored run recipe must be JSON.');
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored run recipe must decode to an object.');
        }

        $runId = $model->getKey();

        if (! is_string($runId)) {
            throw new InvalidArgumentException('Stored run recipe requires its persisted run ID.');
        }

        return RunRecipe::fromArray($decoded, RunObjectKeys::prefix($runId));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! $value instanceof RunRecipe) {
            throw new InvalidArgumentException('Run recipe attributes require a typed RunRecipe.');
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
