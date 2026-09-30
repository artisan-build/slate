<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Models\Run;
use App\Models\RunTrust;
use App\Slate\Execution\RunExecutor;
use ArtisanBuild\BuiltForCloud\Subject;
use Illuminate\Support\Facades\DB;

final readonly class RunManager
{
    public function __construct(private RunExecutor $executor) {}

    public function create(
        string $runId,
        RunRecipe $recipe,
        Subject $owner,
        RunTrust $trust = RunTrust::Untrusted,
        ?string $webhookUrl = null,
        ?string $webhookSecret = null,
    ): Run {
        $run = Run::createWithRecipe($runId, $recipe, $owner, $trust, $webhookUrl, $webhookSecret);

        DB::afterCommit(function () use ($runId): void {
            $this->executor->execute(Run::query()->findOrFail($runId));
        });

        return $run;
    }
}
