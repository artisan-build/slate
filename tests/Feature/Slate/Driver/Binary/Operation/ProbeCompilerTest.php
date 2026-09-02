<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\ProbeCompiler;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\Probe;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles a media probe and matches only the concrete probe operation', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp3');
        file_put_contents($input->writablePath(), 'audio');
        $operation = new Probe($input);
        $impostor = new class implements Operation
        {
            public function name(): OperationName
            {
                return OperationName::Probe;
            }
        };
        $compiler = new ProbeCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime'));

        $recipe = $compiler->compile($operation);

        expect($compiler->supports($operation))->toBeTrue()
            ->and($compiler->supports($impostor))->toBeFalse()
            ->and($recipe->command())->toBe([
                '/runtime/ffmpeg/bin/ffprobe',
                '-v',
                'error',
                '-show_format',
                '-show_streams',
                '-of',
                'json',
                $input->readablePath(),
            ]);
    });
});
