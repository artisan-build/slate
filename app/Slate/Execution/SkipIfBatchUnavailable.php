<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderRunOperation;
use Closure;

final class SkipIfBatchUnavailable
{
    public function handle(RenderFanoutOperation|RenderRunOperation $job, Closure $next): mixed
    {
        $batch = $job->batch();

        if ($batch?->cancelled() || ($job->batchId !== null && $batch === null)) {
            return null;
        }

        return $next($job);
    }
}
