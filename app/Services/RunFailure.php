<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
use App\Models\RunStatus;

final readonly class RunFailure
{
    public function __construct(private RunCleanup $cleanup) {}

    public function handle(string $runId): void
    {
        $run = Run::query()->findOrFail($runId);

        $run->transitionTo(RunStatus::Failed);

        if ($run->status === RunStatus::Failed) {
            $this->cleanup->allObjects($run);
        }
    }
}
