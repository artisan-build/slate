<?php

namespace App\Models;

use App\Jobs\DeliverRunWebhook;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunStillClip;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * @property string $id
 * @property RunStatus $status
 * @property RunTrust $trust
 * @property InputRejectionReason|null $rejection_reason
 * @property SubjectType $owner_subject_type
 * @property string $owner_subject_ref
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property-read RunRecipe $recipe
 *
 * @mixin IdeHelperRun
 */
#[Fillable(['status'])]
final class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $hidden = ['webhook_url', 'webhook_secret'];

    public static function createWithRecipe(
        string $runId,
        RunRecipe $recipe,
        Subject $owner,
        RunTrust $trust = RunTrust::Untrusted,
        ?string $webhookUrl = null,
        ?string $webhookSecret = null,
    ): self {
        if (($webhookUrl === null) !== ($webhookSecret === null)) {
            throw new InvalidArgumentException('A run webhook URL and secret must be configured together.');
        }

        self::assertRecipeObjectOwnership($runId, $recipe);
        $run = new self;
        $run->setRawAttributes([
            'id' => $runId,
            'recipe' => $recipe->toJson(),
            'owner_subject_type' => $owner->type->value,
            'owner_subject_ref' => $owner->ref,
            'trust' => $trust->value,
            'status' => RunStatus::Queued->value,
        ]);

        if ($webhookUrl !== null && $webhookSecret !== null) {
            $run->webhook_url = $webhookUrl;
            $run->webhook_secret = $webhookSecret;
        }

        $run->save();

        return $run;
    }

    public function recipe(): RunRecipe
    {
        return $this->recipe;
    }

    /** @return Builder<self> */
    public static function ownedBy(Subject $subject): Builder
    {
        return self::query()
            ->where('owner_subject_type', $subject->type->value)
            ->where('owner_subject_ref', $subject->ref);
    }

    protected function getRecipeAttribute(mixed $value): RunRecipe
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Stored run recipe must be JSON.');
        }

        return RunRecipe::fromJson($value, RunObjectKeys::prefix($this->id));
    }

    /**
     * Closes ordinary Eloquent recipe writes: property assignment, fill/forceFill, model creation, and
     * unguarded factories all pass here; replicate() is prohibited separately. Deliberate privileged
     * escapes remain outside this guard: setRawAttributes() followed by save()/saveQuietly(), Eloquent
     * bulk update()/upsert(), query-builder insert/update/upsert(), DB::table('runs')->update(), and raw
     * SQL. createWithRecipe() intentionally uses raw attributes only after typed ownership validation.
     */
    public function setAttribute(mixed $key, mixed $value): mixed
    {
        if ($key === 'recipe') {
            if ($this->exists) {
                throw new LogicException('A persisted Run recipe is immutable.');
            }

            $runId = $this->getAttribute('id');

            if (! is_string($value)) {
                throw new InvalidArgumentException('Run recipe creation requires a JSON payload.');
            }

            if (is_string($runId)) {
                self::assertRecipeJsonOwnership($runId, $value);
            }
        } elseif ($key === $this->getKeyName()) {
            if ($this->exists && $value !== $this->getRawOriginal($this->getKeyName())) {
                throw new LogicException('A persisted Run ID is immutable.');
            }

            if (! $this->exists && is_string($value)) {
                $recipe = $this->getAttributes()['recipe'] ?? null;

                if (is_string($recipe)) {
                    self::assertRecipeJsonOwnership($value, $recipe);
                }
            }
        }

        return parent::setAttribute($key, $value);
    }

    /** @param list<string>|null $except */
    public function replicate(?array $except = null): never
    {
        throw new LogicException('Run replication is prohibited because recipe object keys belong to the source run.');
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
        $this->dispatchTerminalWebhook($changed);

        return $changed;
    }

    public function reject(InputRejectionReason $reason): bool
    {
        $changed = DB::transaction(function () use ($reason): bool {
            $run = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($run->status->isTerminal()) {
                return false;
            }

            $run->status = RunStatus::Failed;
            $run->rejection_reason = $reason;

            return $run->save();
        });

        $this->refresh();
        $this->dispatchTerminalWebhook($changed);

        return $changed;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'trust' => RunTrust::class,
            'rejection_reason' => InputRejectionReason::class,
            'owner_subject_type' => SubjectType::class,
            'webhook_url' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    private function dispatchTerminalWebhook(bool $changed): void
    {
        if ($changed && $this->status->isTerminal() && $this->webhook_url !== null) {
            dispatch(new DeliverRunWebhook($this->id));
        }
    }

    private static function assertRecipeObjectOwnership(string $runId, RunRecipe $recipe): void
    {
        $prefix = RunObjectKeys::prefix($runId);

        foreach ($recipe->operations as $operation) {
            if (! $operation instanceof RunStillClip && ! $operation instanceof RunVideoClip) {
                throw new InvalidArgumentException('Run recipe ownership received an unsupported operation.');
            }

            if ($operation instanceof RunStillClip) {
                ObjectKey::withinPrefix($prefix, $operation->image->value());
                ObjectKey::withinPrefix($prefix, $operation->audio->value());
            } else {
                ObjectKey::withinPrefix($prefix, $operation->source->value());
            }
        }
    }

    private static function assertRecipeJsonOwnership(string $runId, string $recipe): void
    {
        self::assertRecipeObjectOwnership(
            $runId,
            RunRecipe::fromJson($recipe, RunObjectKeys::prefix($runId)),
        );
    }
}
