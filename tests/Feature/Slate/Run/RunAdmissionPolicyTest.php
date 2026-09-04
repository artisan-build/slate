<?php

declare(strict_types=1);

namespace Tests\Feature\Slate\Run;

use App\Slate\Run\RunAdmissionPolicy;
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
}
