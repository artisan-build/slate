<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\AudioPadCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioPad;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles leading and trailing padding to a bounded audio filter', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp3');
        $output = $scratch->file('padded.mp3');
        file_put_contents($input->writablePath(), 'audio');
        $operation = new AudioPad($input, $output, 0.35, 1.25, AudioBitrate::Kbps128);

        $recipe = (new AudioPadCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')))->compile($operation);

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            '-y',
            '-i',
            $input->readablePath(),
            '-af',
            'adelay=delays=350:all=1,apad=pad_dur=1.25',
            '-c:a',
            'libmp3lame',
            '-b:a',
            '128k',
            '-f',
            'mp3',
            $output->writablePath(),
        ]);
    });
});
