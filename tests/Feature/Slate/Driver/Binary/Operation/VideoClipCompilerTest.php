<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\VideoClipCompiler;
use App\Slate\Recipe\Video\VideoClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('stream copies only the padded coarse intermediate and reencodes the precise deliverable', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = $scratch->file('source.mp4');
        file_put_contents($source->writablePath(), 'video');
        $compiler = new VideoClipCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        );
        $coarsePath = $scratch->file('coarse.mp4');
        $coarse = $compiler->compile(VideoClip::coarse(
            $source,
            12_000_000,
            20_000_000,
            120.0,
            $coarsePath,
        ))->command();
        file_put_contents($coarsePath->writablePath(), 'coarse video');
        $precise = $compiler->compile(VideoClip::precise(
            $coarsePath,
            5_000_000,
            8_000_000,
            $scratch->file('output.mp4'),
        ))->command();

        expect($coarse)->toContain('-ss', '7', '-t', '18', '-c', 'copy')
            ->and($precise)->toContain('-ss', '5', '-t', '8', '-c:v', 'libopenh264', '-c:a', 'aac')
            ->and($precise)->not->toContain('copy')
            ->and(implode(' ', $precise))->toContain('scale=1536:1024:force_original_aspect_ratio=decrease')
            ->and(implode(' ', $precise))->toContain('pad=1536:1024:(ow-iw)/2:(oh-ih)/2:color=black')
            ->and($precise)->not->toContain('libx264')
            ->and($precise)->not->toContain('libx265');
    });
});
