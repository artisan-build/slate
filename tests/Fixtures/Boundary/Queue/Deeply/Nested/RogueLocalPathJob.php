<?php

declare(strict_types=1);

namespace Tests\Fixtures\Boundary\Queue\Deeply\Nested;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RogueLocalPathJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $inputPath) {}

    public function handle(): void {}
}
