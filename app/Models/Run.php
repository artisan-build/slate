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
        $recipe = $this->getRawOriginal('recipe');

        if (! is_string($recipe)) {
            throw new InvalidArgumentException('Stored run recipe must be JSON.');
        }

        return RunRecipe::fromJson($recipe, RunObjectKeys::prefix($this->id));
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
