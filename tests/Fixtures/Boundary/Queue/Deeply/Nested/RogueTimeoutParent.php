<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

class RogueTimeoutParent implements ShouldQueue
{
    public int $timeout = 60;
}
