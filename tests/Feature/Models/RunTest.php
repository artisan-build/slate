<?php

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunRecipeOperation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Queue\RedeliveredRunTransition;

uses(LazilyRefreshDatabase::class);

function persistedRunRecipe(): RunRecipe
{
    return new RunRecipe([
        new RunRecipeOperation(
            OperationName::VideoStillClip,
            QualityTier::Delivery,
            120_000_000,
            true,
        ),
        new RunRecipeOperation(
            OperationName::VideoConcat,
            QualityTier::Delivery,
            240_000_000,
            true,
        ),
    ]);
}

test('persists and round trips an equal typed recipe without compiled command vocabulary', function (): void {
    $recipe = persistedRunRecipe();
    $run = Run::factory()->create(['recipe' => $recipe]);

    $storedRecipe = DB::table('runs')->where('id', $run->id)->value('recipe');
    $reloaded = Run::query()->findOrFail($run->id);

    expect($reloaded->recipe->toArray())->toBe($recipe->toArray())
        ->and($reloaded->status)->toBe(RunStatus::Queued)
        ->and($storedRecipe)->toBeString()
        ->and($storedRecipe)->not->toContain('argv')
        ->and($storedRecipe)->not->toContain('-filter_complex')
        ->and($storedRecipe)->not->toContain('drawtext=')
        ->and($storedRecipe)->not->toContain('libopenh264');
});

test('persists every run lifecycle status as its typed enum value', function (RunStatus $status): void {
    $run = Run::factory()->create(['status' => $status]);

    expect(Run::query()->findOrFail($run->id)->status)->toBe($status)
        ->and(DB::table('runs')->where('id', $run->id)->value('status'))->toBe($status->value);
})->with([
    'queued' => [RunStatus::Queued],
    'running' => [RunStatus::Running],
    'succeeded' => [RunStatus::Succeeded],
    'failed' => [RunStatus::Failed],
]);

test('reapplying a status transition is a no op and a terminal status rejects a late transition', function (): void {
    $run = Run::factory()->create();

    expect($run->transitionTo(RunStatus::Running))->toBeTrue()
        ->and($run->transitionTo(RunStatus::Running))->toBeFalse()
        ->and($run->transitionTo(RunStatus::Succeeded))->toBeTrue()
        ->and($run->transitionTo(RunStatus::Running))->toBeFalse()
        ->and($run->status)->toBe(RunStatus::Succeeded);
});

test('database queue redelivery after an in flight running transition preserves the terminal status', function (): void {
    config()->set('queue.connections.database.retry_after', 1);
    $run = Run::factory()->create();
    $databaseQueue = Queue::connection('database');
    $databaseQueue->push(new RedeliveredRunTransition($run->id, RunStatus::Running), '', 'render');

    $firstReservation = $databaseQueue->pop('render');
    expect($firstReservation)->not->toBeNull();
    $firstCommand = unserialize($firstReservation->payload()['data']['command']);
    expect($firstCommand)->toBeInstanceOf(RedeliveredRunTransition::class);
    $firstCommand->handle();
    $run->refresh();
    expect($run->status)->toBe(RunStatus::Running);

    $run->transitionTo(RunStatus::Succeeded);
    DB::table('jobs')->where('id', $firstReservation->getJobId())->update([
        'reserved_at' => now()->subSeconds(2)->getTimestamp(),
    ]);

    $redeliveredReservation = $databaseQueue->pop('render');
    expect($redeliveredReservation)->not->toBeNull()
        ->and($redeliveredReservation->attempts())->toBe(2);
    $redeliveredCommand = unserialize($redeliveredReservation->payload()['data']['command']);
    expect($redeliveredCommand)->toBeInstanceOf(RedeliveredRunTransition::class);
    $redeliveredCommand->handle();
    $redeliveredReservation->delete();

    expect(Run::query()->findOrFail($run->id)->status)->toBe(RunStatus::Succeeded);
});
