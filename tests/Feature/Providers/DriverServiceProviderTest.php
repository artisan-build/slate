<?php

use App\Providers\DriverServiceProvider;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioConcat;
use App\Slate\Recipe\Audio\AudioConcatInput;
use App\Slate\Recipe\Audio\AudioConcatMode;
use App\Slate\Recipe\Audio\AudioConcatSilence;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioPad;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('registers production compilers for every shipped audio operation', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp3');
        file_put_contents($input->writablePath(), 'audio');
        $compiler = resolve(RecipeCompiler::class);
        $operations = [
            new Probe($input),
            new AudioSilence(
                $scratch->file('silence.mp3'),
                1.0,
                AudioSampleRate::Hz44100,
                AudioChannelLayout::Mono,
                AudioFormat::Mp3,
                AudioBitrate::Kbps128,
            ),
            new AudioConcat(
                [new AudioConcatInput($input), new AudioConcatSilence(0.5)],
                AudioConcatMode::Reencode,
                AudioBitrate::Kbps128,
                $scratch->file('concat.mp3'),
            ),
            new AudioPad($input, $scratch->file('padded.mp3'), 0.25, 0.5, AudioBitrate::Kbps128),
        ];

        $executables = array_map(
            static fn (Operation $operation): string => basename($compiler->compile($operation)->command()[0]),
            $operations,
        );

        expect(app()->getProviders(DriverServiceProvider::class))->toHaveCount(1)
            ->and($compiler)->toBeInstanceOf(RecipeCompiler::class)
            ->and($executables)->toBe(['ffprobe', 'ffmpeg', 'ffmpeg', 'ffmpeg']);
    });
});
