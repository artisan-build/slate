<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue as QueueContract;

final class RogueAliasedTimeoutJob implements QueueContract
{
    public int $timeout = 60;
}
