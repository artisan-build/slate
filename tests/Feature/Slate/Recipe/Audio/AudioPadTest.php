<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioPad;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts only finite non-negative lead and trail padding', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp3');
        $output = $scratch->file('padded.mp3');

        foreach ([
            [-0.1, 0.0],
            [0.0, -0.1],
            [INF, 0.0],
            [0.0, NAN],
        ] as [$lead, $trail]) {
            expect(fn (): AudioPad => new AudioPad($input, $output, $lead, $trail, AudioBitrate::Kbps128))
                ->toThrow(InvalidArgumentException::class);
        }

        $operation = new AudioPad($input, $output, 0.0, 1.25, AudioBitrate::Kbps128);

        expect($operation->name())->toBe(OperationName::AudioPad)
            ->and($operation->leadSilenceSeconds)->toBe(0.0)
            ->and($operation->trailSilenceSeconds)->toBe(1.25);
    });
});

test('rejects padding durations the compiler cannot preserve', function (float $lead, float $trail): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($lead, $trail): void {
        expect(fn (): AudioPad => new AudioPad(
            $scratch->file('input.mp3'),
            $scratch->file('padded.mp3'),
            $lead,
            $trail,
            AudioBitrate::Kbps128,
        ))->toThrow(InvalidArgumentException::class);
    });
})->with([
    'lead below millisecond precision' => [0.0000001, 0.0],
    'lead half-millisecond boundary' => [0.0005, 0.0],
    'lead largest finite float' => [PHP_FLOAT_MAX, 0.0],
    'trail below microsecond precision' => [0.0, 0.0000001],
    'trail half-microsecond boundary' => [0.0, 0.0000005],
    'trail largest finite float' => [0.0, PHP_FLOAT_MAX],
]);
