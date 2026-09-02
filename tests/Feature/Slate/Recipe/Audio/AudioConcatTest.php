<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioConcat;
use App\Slate\Recipe\Audio\AudioConcatInput;
use App\Slate\Recipe\Audio\AudioConcatMode;
use App\Slate\Recipe\Audio\AudioConcatSilence;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('rejects malformed item lists and silence in copy mode', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $output = $scratch->file('joined.mp3');
        $input = new AudioConcatInput($scratch->file('input.mp3'));

        expect(fn (): AudioConcat => new AudioConcat([], AudioConcatMode::Reencode, AudioBitrate::Kbps128, $output))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): AudioConcat => new AudioConcat([1 => $input], AudioConcatMode::Reencode, AudioBitrate::Kbps128, $output))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): AudioConcat => new AudioConcat([$input, new stdClass], AudioConcatMode::Reencode, AudioBitrate::Kbps128, $output))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn (): AudioConcat => new AudioConcat([new AudioConcatSilence(0.5)], AudioConcatMode::Copy, AudioBitrate::Kbps128, $output))
            ->toThrow(InvalidArgumentException::class);
    });
});

test('accepts inline silence gaps only within the customer range', function (): void {
    foreach ([0.0, -0.1, 1.500001, INF, NAN] as $duration) {
        expect(fn (): AudioConcatSilence => new AudioConcatSilence($duration))
            ->toThrow(InvalidArgumentException::class);
    }

    expect((new AudioConcatSilence(1.5))->durationSeconds)->toBe(1.5);
});

test('rejects inline silence below compiler precision', function (): void {
    expect(fn (): AudioConcatSilence => new AudioConcatSilence(0.0000001))
        ->toThrow(InvalidArgumentException::class);
});
