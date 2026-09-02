<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use Illuminate\Support\Facades\Process;

function runCallerCommand(FfmpegLocator $locator, string $callerInput): void
{
    $command = [$locator->ffmpegPath(), '-i', $callerInput];

    Process::run($command);
}
