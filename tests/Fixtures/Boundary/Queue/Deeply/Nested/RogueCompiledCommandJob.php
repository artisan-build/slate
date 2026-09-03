<?php

declare(strict_types=1);

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RogueCompiledCommandJob implements ShouldQueue
{
    use Queueable;

    /** @param non-empty-list<string> $command */
    public function __construct(public array $command) {}

    public function handle(): void {}
}
