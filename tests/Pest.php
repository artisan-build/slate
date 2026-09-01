<?php

use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

function ffmpegFixture(string $name): string
{
    $contents = file_get_contents(__DIR__.'/Fixtures/'.$name);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to load fixture {$name}.");
    }

    return $contents;
}
