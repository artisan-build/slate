<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\AudioSilenceCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles typed stereo silence to an MP3 command', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $output = $scratch->file('silence.mp3');
        $operation = new AudioSilence(
            $output,
            1.25,
            AudioSampleRate::Hz44100,
            AudioChannelLayout::Stereo,
            AudioFormat::Mp3,
            AudioBitrate::Kbps192,
        );

        $recipe = (new AudioSilenceCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')))->compile($operation);

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            '-y',
            '-f',
            'lavfi',
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            'anullsrc=r=44100:cl=stereo',
            ...ffmpegOutputHardeningArguments(),
            '-t',
            '1.25',
            '-c:a',
            'libmp3lame',
            '-ac',
            '2',
            '-b:a',
            '192k',
            '-f',
            'mp3',
            $output->writablePath(),
        ]);
    });
});
