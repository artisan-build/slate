<?php

declare(strict_types=1);

namespace Tests\Fixtures\Queue;

use App\Models\Run;
use App\Models\RunStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RedeliveredRunTransition implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $runId,
        public RunStatus $status,
    ) {}

    public function handle(): void
    {
        Run::query()->findOrFail($this->runId)->transitionTo($this->status);
    }
}
