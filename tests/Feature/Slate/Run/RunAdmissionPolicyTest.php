<?php

declare(strict_types=1);

namespace Tests\Feature\Slate\Run;

use App\Slate\Run\RunAdmissionPolicy;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\RunObjectKeys;
use Tests\TestCase;

final class RunAdmissionPolicyTest extends TestCase
{
    public function test_derives_run_caps_from_worker_waves_and_the_runtime_output_budget(): void
    {
        $policy = resolve(RunAdmissionPolicy::class);

        self::assertSame(100, $policy->maximumQueueJobs());
        self::assertSame(49, $policy->maximumOperations());
        self::assertSame(4_900, $policy->maximumTotalOutputSeconds());
    }

    // The video-clip estimate is 2 jobs per clip plus one completion job: 49
    // clips estimate to 99 jobs, the largest count the 100-job ceiling can
    // admit. This pins the video estimate at that boundary; inflating the
    // per-clip cost or misclassifying clips must fail admission here.
    public function test_a_maximal_video_clip_recipe_estimates_to_99_jobs_and_is_admitted(): void
    {
        $policy = resolve(RunAdmissionPolicy::class);
        $clips = [];

        foreach (range(0, 48) as $index) {
            $clips[] = new RunVideoClip(
                RunObjectKeys::input('41414141-4141-4141-8141-414141414141', $index, 'source.mp4'),
                0.0,
                98.0,
            );
        }

        self::assertNull($policy->rejectionReason(new RunRecipe($clips)));
    }
}
