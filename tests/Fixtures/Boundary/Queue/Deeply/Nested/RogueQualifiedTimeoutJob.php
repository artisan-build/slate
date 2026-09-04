<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RogueQualifiedTimeoutJob implements ShouldQueue
{
    public int $timeout = 2_400;
}
