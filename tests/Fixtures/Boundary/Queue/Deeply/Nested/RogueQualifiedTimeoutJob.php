<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

final class RogueQualifiedTimeoutJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public int $timeout = 2_400;
}
