<?php

use App\Slate\Recipe\Caption\TimedWord;

test('accepts typed position and chronological timing data with user text', function (): void {
    $word = TimedWord::fromValues("Mia's story", 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15);

    expect($word->text)->toBe("Mia's story")
        ->and($word->xPixels)->toBe(128)
        ->and($word->yPixels)->toBe(720)
        ->and($word->enterSeconds)->toBe(1.25)
        ->and($word->enterDurationSeconds)->toBe(0.2)
        ->and($word->spokenEndSeconds)->toBe(2.5)
        ->and($word->clearSeconds)->toBe(3.0)
        ->and($word->exitDurationSeconds)->toBe(0.15);
});

test('rejects invalid timed word parameters', function (array $values): void {
    expect(fn (): TimedWord => TimedWord::fromValues(...$values))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty text' => [['', 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'control character in text' => [["story\0", 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative x position' => [['story', -1, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative y position' => [['story', 128, -1, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative timeline value' => [['story', 128, 720, -1.0, 0.2, 2.5, 3.0, 0.15]],
    'non-finite timeline value' => [['story', 128, 720, INF, 0.2, 2.5, 3.0, 0.15]],
    'zero enter duration' => [['story', 128, 720, 1.25, 0.0, 2.5, 3.0, 0.15]],
    'zero exit duration' => [['story', 128, 720, 1.25, 0.2, 2.5, 3.0, 0.0]],
    'entry ends after speech' => [['story', 128, 720, 1.25, 1.5, 2.5, 3.0, 0.15]],
    'clear precedes spoken end' => [['story', 128, 720, 1.25, 0.2, 2.5, 2.0, 0.15]],
]);
