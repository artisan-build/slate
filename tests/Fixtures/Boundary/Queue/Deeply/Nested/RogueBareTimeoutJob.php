<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RogueBareTimeoutJob implements ShouldQueue
{
    public int $timeout = 60;
}
