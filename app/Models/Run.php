<?php

namespace App\Models;

use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunStillClip;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * @property string $id
 * @property RunStatus $status
 * @property-read RunRecipe $recipe
 *
 * @mixin IdeHelperRun
 */
#[Fillable(['status'])]
final class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory, HasUuids;

    public static function createWithRecipe(string $runId, RunRecipe $recipe): self
    {
        self::assertRecipeObjectOwnership($runId, $recipe);
        $run = new self;
        $run->setRawAttributes([
            'id' => $runId,
            'recipe' => $recipe->toJson(),
            'status' => RunStatus::Queued->value,
        ]);
        $run->save();

        return $run;
    }

    public function recipe(): RunRecipe
    {
        return $this->recipe;
    }

    public function getRecipeAttribute(mixed $value): RunRecipe
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Stored run recipe must be JSON.');
        }

        return RunRecipe::fromJson($value, RunObjectKeys::prefix($this->id));
    }

    public function setAttribute(mixed $key, mixed $value): mixed
    {
        if ($key === 'recipe') {
            if ($this->exists) {
                throw new LogicException('A persisted Run recipe is immutable.');
            }

            $runId = $this->getAttribute('id');

            if (! is_string($runId) || ! is_string($value)) {
                throw new InvalidArgumentException('Run recipe creation requires its UUID and JSON payload.');
            }

            self::assertRecipeObjectOwnership(
                $runId,
                RunRecipe::fromJson($value, RunObjectKeys::prefix($runId)),
            );
        }

        return parent::setAttribute($key, $value);
    }

    public function transitionTo(RunStatus $nextStatus): bool
    {
        $changed = DB::transaction(function () use ($nextStatus): bool {
            $run = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($run->status === $nextStatus || $run->status->isTerminal()) {
                return false;
            }

            if (($run->status === RunStatus::Queued && ! in_array($nextStatus, [RunStatus::Running, RunStatus::Failed], true))
                || ($run->status === RunStatus::Running && ! in_array($nextStatus, [RunStatus::Succeeded, RunStatus::Failed], true))) {
                throw new LogicException("Run cannot transition from {$run->status->value} to {$nextStatus->value}.");
            }

            $run->status = $nextStatus;

            return $run->save();
        });

        $this->refresh();

        return $changed;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
        ];
    }

    private static function assertRecipeObjectOwnership(string $runId, RunRecipe $recipe): void
    {
        $prefix = RunObjectKeys::prefix($runId);

        foreach ($recipe->operations as $operation) {
            if (! $operation instanceof RunStillClip) {
                throw new InvalidArgumentException('Run recipe ownership received an unsupported operation.');
            }

            ObjectKey::withinPrefix($prefix, $operation->image->value());
            ObjectKey::withinPrefix($prefix, $operation->audio->value());
        }
    }
}
