<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Jobs\CoarseCutVideoSource;
use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderRunOperation;
use App\Jobs\RenderVideoClip;
use Closure;

final class SkipIfBatchUnavailable
{
    public function handle(
        CoarseCutVideoSource|RenderFanoutOperation|RenderRunOperation|RenderVideoClip $job,
        Closure $next,
    ): mixed {
        $batch = $job->batch();

        if ($batch?->cancelled() || ($job->batchId !== null && $batch === null)) {
            return null;
        }

        return $next($job);
    }
}
