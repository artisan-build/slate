<?php

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RogueTraitTimeoutJob implements ShouldQueue
{
    use RogueTimeoutTrait;
}
