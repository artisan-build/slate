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
    'whitespace-only text' => [['   ', 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'control character in text' => [["story\0", 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'malformed UTF-8 text' => [["\xC3\x28", 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'text exceeds operational bound' => [[str_repeat('a', 129), 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative x position' => [['story', -1, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'x position exceeds canvas' => [['story', 1536, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative y position' => [['story', 128, -1, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'y position exceeds canvas' => [['story', 128, 1536, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'negative timeline value' => [['story', 128, 720, -1.0, 0.2, 2.5, 3.0, 0.15]],
    'non-finite timeline value' => [['story', 128, 720, NAN, 0.2, 2.5, 3.0, 0.15]],
    'sub-microsecond timeline value' => [['story', 128, 720, 0.0000001, 0.2, 2.5, 3.0, 0.15]],
    'timeline exceeds operational bound' => [['story', 128, 720, 1.25, 0.2, 2.5, 86400.000001, 0.15]],
    'zero enter duration' => [['story', 128, 720, 1.25, 0.0, 2.5, 3.0, 0.15]],
    'zero exit duration' => [['story', 128, 720, 1.25, 0.2, 2.5, 3.0, 0.0]],
    'entry ends after speech' => [['story', 128, 720, 1.25, 1.5, 2.5, 3.0, 0.15]],
    'clear precedes spoken end' => [['story', 128, 720, 1.25, 0.2, 2.5, 2.0, 0.15]],
]);

test('accepts accepted-side boundaries of the timed word bounds', function (array $values): void {
    // Pins the exact accepted bounds: anything looser (>= for maxima, <= for minima,
    // non-strict chronology) turns these rows red. Kills the boundary mutants on
    // TimedWord's cap, canvas and chronology comparisons.
    $word = TimedWord::fromValues(...$values);

    expect($word->text)->toBe($values[0]);
})->with([
    'text at the 128-character cap' => [[str_repeat('a', 128), 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'coordinates at the canvas maximum' => [['story', 1535, 1535, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'coordinates at zero' => [['story', 0, 0, 1.25, 0.2, 2.5, 3.0, 0.15]],
    'entry ends exactly at spoken end and clear equals spoken end' => [['story', 128, 720, 1.25, 1.25, 2.5, 2.5, 0.15]],
]);
