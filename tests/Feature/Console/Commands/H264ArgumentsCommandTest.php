<?php

use App\Slate\Driver\Binary\H264RateControl;
use App\Slate\Recipe\QualityTier;

test('prints the shipped tier arguments for the comparison harness', function (): void {
    $arguments = H264RateControl::argumentsFor(QualityTier::Intermediate, 1536, 1024, 24);

    $command = $this->artisan('slate:h264-arguments', [
        'width' => 1536,
        'height' => 1024,
        'fps' => 24,
        '--tier' => 'intermediate',
    ]);

    foreach ($arguments as $argument) {
        $command->expectsOutput($argument);
    }

    $command->assertSuccessful();
});

test('prints calibration arguments without changing the named tiers', function (): void {
    $command = $this->artisan('slate:h264-arguments', [
        'width' => 1280,
        'height' => 720,
        'fps' => 30,
        '--bits-per-pixel' => '0.06',
    ]);

    $command->expectsOutput('1658880')
        ->expectsOutput('yuv420p')
        ->expectsOutput('aac')
        ->assertSuccessful();
});
