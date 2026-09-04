<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Input\InputRejectionReason;

final readonly class RunFailure
{
    public function __construct(private RunCleanup $cleanup) {}

    public function handle(string $runId, ?InputRejectionReason $rejectionReason = null): void
    {
        $run = Run::query()->findOrFail($runId);

        if ($rejectionReason === null) {
            $run->transitionTo(RunStatus::Failed);
        } else {
            $run->reject($rejectionReason);
        }

        if ($run->status === RunStatus::Failed) {
            $this->cleanup->allObjects($run);
        }
    }
}
