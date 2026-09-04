<?php

namespace App\Models;

use App\Casts\RunRecipeCast;
use App\Slate\Run\RunRecipe;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property string $id
 * @property RunRecipe $recipe
 * @property RunStatus $status
 *
 * @mixin IdeHelperRun
 */
#[Fillable(['recipe', 'status'])]
final class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory, HasUuids;

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
            'recipe' => RunRecipeCast::class,
            'status' => RunStatus::class,
        ];
    }
}
