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
            [0.0000001, 0.0],
            [PHP_FLOAT_MAX, 0.0],
            [0.0, -0.1],
            [0.0, 0.0000001],
            [0.0, PHP_FLOAT_MAX],
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
