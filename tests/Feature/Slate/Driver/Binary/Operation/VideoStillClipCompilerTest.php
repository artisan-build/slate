<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\VideoStillClipCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\KenBurnsDirection;
use App\Slate\Recipe\Video\KenBurnsEase;
use App\Slate\Recipe\Video\KenBurnsMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFadeOut;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles fit motion fade and normalization into one simple filtergraph', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $image = $scratch->file('image.png');
        $audio = $scratch->file('audio.mp3');
        file_put_contents($image->writablePath(), 'image');
        file_put_contents($audio->writablePath(), 'audio');
        $operation = new VideoStillClip(
            $image, $audio, $scratch->file('clip.mp4'), 1536, 1024, 24, 2.0,
            VideoFit::ContainPad, VideoColor::Black,
            new KenBurnsMotion(6.0, KenBurnsDirection::In, KenBurnsEase::Sine), 2,
            QualityTier::Intermediate, AudioBitrate::Kbps192,
            new VideoFadeOut(1.5, 0.5, VideoColor::Black),
        );

        $command = (new VideoStillClipCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-vf', $command, true);

        expect($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toBe(
                'scale=3072:2048:force_original_aspect_ratio=decrease,'
                .'pad=3072:2048:(ow-iw)/2:(oh-ih)/2:color=black,'
                .'zoompan=z=1+0.06*(1-cos(PI*on/48))/2:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):d=48:s=3072x2048:fps=24,'
                .'scale=1536:1024,fade=type=out:st=1.5:d=0.5:color=black,'
                .'scale=in_range=auto:out_range=tv,format=yuv420p',
            )
            ->and(array_count_values($command)['-vf'])->toBe(1)
            ->and($command)->not->toContain('-filter_complex')
            ->and($command)->toContain('libopenh264', '0:v:0', '1:a:0');
    });
});

test('rejects a different operation despite its operation name', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $compiler = new VideoStillClipCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        );

        expect(fn () => $compiler->compile(new Probe($scratch->file('input.mp4'))))
            ->toThrow(InvalidArgumentException::class, 'only VideoStillClip');
    });
});
