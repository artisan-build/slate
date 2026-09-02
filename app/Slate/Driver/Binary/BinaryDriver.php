<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

final readonly class BinaryDriver implements Driver
{
    public function __construct(private int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('The binary driver timeout must be at least one second.');
        }
    }

    public function run(CompiledRecipe $recipe): DriverResult
    {
        $process = Process::timeout($this->timeoutSeconds);

        if ($recipe->workingDirectory() !== null) {
            $process = $process->path($recipe->workingDirectory());
        }

        $result = $process->run($recipe->command());

        return new DriverResult(
            $result->output(),
            $result->errorOutput(),
            $result->exitCode(),
        );
    }
}
