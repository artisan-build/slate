<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\AudioConcatCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioConcat;
use App\Slate\Recipe\Audio\AudioConcatInput;
use App\Slate\Recipe\Audio\AudioConcatMode;
use App\Slate\Recipe\Audio\AudioConcatSilence;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles copy mode with a compiler-owned concat manifest', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $first = $scratch->file('first.mp3');
        $second = $scratch->file("second's.mp3");
        $output = $scratch->file('joined.mp3');
        file_put_contents($first->writablePath(), 'first');
        file_put_contents($second->writablePath(), 'second');
        $operation = new AudioConcat(
            [new AudioConcatInput($first), new AudioConcatInput($second)],
            AudioConcatMode::Copy,
            AudioBitrate::Kbps128,
            $output,
        );

        $recipe = (new AudioConcatCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')))->compile($operation);
        $command = $recipe->command();
        $inputIndex = array_search('-i', $command, true);
        expect($inputIndex)->toBeInt();
        $manifestPath = $command[$inputIndex + 1];

        expect($command)->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            $manifestPath,
            ...ffmpegOutputHardeningArguments(),
            '-c:a',
            'copy',
            '-f',
            'mp3',
            $output->writablePath(),
        ])->and(file_get_contents($manifestPath))->toBe(
            "ffconcat version 1.0\nfile '{$first->readablePath()}'\nfile '".
            dirname($second->readablePath())."/second'\\''s.mp3'\n",
        );
    });
});

test('compiles reencode mode with inline silence in item order', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $first = $scratch->file('first.mp3');
        $second = $scratch->file('second.mp3');
        $output = $scratch->file('joined.mp3');
        file_put_contents($first->writablePath(), 'first');
        file_put_contents($second->writablePath(), 'second');
        $operation = new AudioConcat(
            [new AudioConcatInput($first), new AudioConcatSilence(0.75), new AudioConcatInput($second)],
            AudioConcatMode::Reencode,
            AudioBitrate::Kbps192,
            $output,
        );

        $recipe = (new AudioConcatCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')))->compile($operation);

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            '-y',
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            $first->readablePath(),
            '-f',
            'lavfi',
            '-t',
            '0.75',
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            'anullsrc=r=44100:cl=mono',
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            $second->readablePath(),
            '-filter_complex',
            '[0:a][1:a][2:a]concat=n=3:v=0:a=1[audio]',
            '-map',
            '[audio]',
            ...ffmpegOutputHardeningArguments(),
            '-c:a',
            'libmp3lame',
            '-b:a',
            '192k',
            '-f',
            'mp3',
            $output->writablePath(),
        ]);
    });
});
