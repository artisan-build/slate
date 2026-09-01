<?php

use App\Jobs\PrepareFfmpegBenchmark;
use Illuminate\Support\Facades\Queue;

test('dispatches the coordinator to the default queue connection', function (): void {
    Queue::fake();

    $this->artisan('slate:benchmark-queue', [
        '--duration' => 30,
        '--chunk' => 5,
    ])->assertSuccessful();

    Queue::assertPushed(PrepareFfmpegBenchmark::class, fn (PrepareFfmpegBenchmark $job): bool => $job->durationSeconds === 30
        && $job->chunkSeconds === 5
        && $job->connection === null);
});

test('rejects an invalid chunk length without dispatching', function (): void {
    Queue::fake();

    $this->artisan('slate:benchmark-queue', [
        '--duration' => 10,
        '--chunk' => 20,
    ])->assertFailed();

    Queue::assertNothingPushed();
});
