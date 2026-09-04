<?php

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunStillClip;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Queue\RedeliveredRunTransition;

uses(LazilyRefreshDatabase::class);

function persistedRunRecipe(string $runId): RunRecipe
{
    $prefix = RunObjectKeys::prefix($runId);

    return new RunRecipe([
        new RunStillClip(
            ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0000/image.ppm'),
            ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0000/audio.mp3'),
            1536,
            1024,
            24,
            120_000_000,
            VideoFit::ContainPad,
            VideoColor::Black,
            2,
            QualityTier::Delivery,
            AudioBitrate::Kbps192,
        ),
        new RunStillClip(
            ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0001/image.ppm'),
            ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0001/audio.mp3'),
            1024,
            1536,
            24,
            30_000_000,
            VideoFit::ContainPad,
            VideoColor::Black,
            1,
            QualityTier::Intermediate,
            AudioBitrate::Kbps192,
        ),
    ]);
}

test('persists and round trips an equal typed recipe without compiled command vocabulary', function (): void {
    $runId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $recipe = persistedRunRecipe($runId);
    $run = Run::createWithRecipe($runId, $recipe);

    $storedRecipe = DB::table('runs')->where('id', $run->id)->value('recipe');
    $reloaded = Run::query()->findOrFail($run->id);
    expect($storedRecipe)->toBeString();
    $decoded = json_decode($storedRecipe, true, flags: JSON_THROW_ON_ERROR);

    expect($reloaded->recipe()->toArray())->toBe($recipe->toArray())
        ->and($reloaded->status)->toBe(RunStatus::Queued)
        ->and($decoded)->toBe($recipe->toArray())
        ->and(knownCompiledVocabularyViolations($decoded))->toBe([]);
});

test('rejects foreign object keys at the only typed recipe creation seam', function (): void {
    $runId = '19191919-1919-4919-8919-191919191919';
    $foreignRunId = '20202020-2020-4020-8020-202020202020';

    expect(fn () => Run::createWithRecipe($runId, persistedRunRecipe($foreignRunId)))
        ->toThrow(InvalidArgumentException::class, 'Object key must remain within the caller prefix');

    expect(DB::table('runs')->where('id', $runId)->exists())->toBeFalse();
});

test('exposes the typed recipe only at creation and as a read method afterwards', function (): void {
    $runId = '22222222-2222-4222-8222-222222222224';
    $recipe = persistedRunRecipe($runId);
    $run = Run::createWithRecipe($runId, $recipe);
    Model::preventSilentlyDiscardingAttributes();

    try {
        expect(fn () => $run->update(['recipe' => persistedRunRecipe($runId)]))
            ->toThrow(MassAssignmentException::class);
    } finally {
        Model::preventSilentlyDiscardingAttributes(false);
    }

    expect($run->getFillable())->toBe(['status'])
        ->and($run->getCasts())->not->toHaveKey('recipe')
        ->and($run->fresh()->recipe()->toArray())->toBe($recipe->toArray());
});

test('hydrates a typed recipe after database JSON key reordering', function (): void {
    $runId = '21212121-2121-4121-8121-212121212121';
    $recipe = persistedRunRecipe($runId);
    $payload = $recipe->toArray();
    $payload['operations'] = array_map(
        static fn (array $operation): array => array_reverse($operation, true),
        $payload['operations'],
    );
    DB::table('runs')->insert([
        'id' => $runId,
        'recipe' => json_encode($payload, JSON_THROW_ON_ERROR),
        'status' => RunStatus::Queued->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $run = Run::query()->findOrFail($runId);

    expect($run->recipe()->toArray())->toBe($recipe->toArray());
});

test('persisted payload guard catches command-shaped arrays under an unrelated key', function (): void {
    $violations = knownCompiledVocabularyViolations([
        'metadata' => ['tool', '-i', 'input.mp4', '-y', 'output.mp4'],
    ]);

    expect($violations)->not->toBe([])
        ->and(implode('; ', $violations))->toContain('command-shaped positional arguments');
});

test('persisted payload guard catches every recognized filter fragment', function (string $filter): void {
    expect(knownCompiledVocabularyViolations(['value' => $filter.'=untrusted']))->not->toBe([]);
})->with(array_combine(recognizedFilterNames(), recognizedFilterNames()));

test('persisted payload guard catches every recognized encoder name', function (string $encoder): void {
    expect(knownCompiledVocabularyViolations(['value' => $encoder]))->not->toBe([]);
})->with(array_combine(recognizedEncoderNames(), recognizedEncoderNames()));

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
