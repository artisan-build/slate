<?php

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts only positive finite silence durations', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        foreach ([0.0, -0.1, INF, NAN] as $duration) {
            expect(fn (): AudioSilence => new AudioSilence(
                $scratch->file('silence.mp3'),
                $duration,
                AudioSampleRate::Hz44100,
                AudioChannelLayout::Mono,
                AudioFormat::Mp3,
                AudioBitrate::Kbps128,
            ))->toThrow(InvalidArgumentException::class);
        }

        $operation = new AudioSilence(
            $scratch->file('silence.mp3'),
            1.25,
            AudioSampleRate::Hz44100,
            AudioChannelLayout::Stereo,
            AudioFormat::Mp3,
            AudioBitrate::Kbps192,
        );

        expect($operation->name())->toBe(OperationName::AudioSilence)
            ->and($operation->durationSeconds)->toBe(1.25);
    });
});
