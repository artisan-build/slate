<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Models\RunStatus;
use App\Services\RunCleanup;
use App\Services\RunFailure;
use App\Slate\Execution\QueueRuntimePolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CompleteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public string $runId)
    {
        $settings = resolve(QueueRuntimePolicy::class)->forQueue((string) config('slate.execution.render_queue'));
        $this->timeout = $settings->jobTimeoutSeconds;
        $this->onQueue($settings->queue);
    }

    public function handle(RunCleanup $cleanup): void
    {
        $run = Run::query()->findOrFail($this->runId);
        $cleanup->workObjects($run);
        $run->transitionTo(RunStatus::Succeeded);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(RunFailure::class)->handle($this->runId);
    }
}
