<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\VideoConcatCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function compilerTestVideo(ScratchDirectory $scratch, string $name, float $duration): VideoStillClip
{
    $output = $scratch->file($name.'.mp4');
    file_put_contents($output->writablePath(), $name);

    return new VideoStillClip(
        $scratch->file($name.'.png'), $scratch->file($name.'.mp3'), $output,
        1536, 1024, 24, $duration, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
        QualityTier::Intermediate, AudioBitrate::Kbps192,
    );
}

test('compiles xfade acrossfade and normalization in one complex graph', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $operation = new VideoConcat(
            [
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'first', 2.0)),
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'second', 3.0)),
            ],
            VideoConcatMode::Xfade, VideoTransition::Fade, 0.75, true,
            QualityTier::Delivery, 24, $scratch->file('joined.mp4'),
        );

        $command = (new VideoConcatCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-filter_complex', $command, true);

        expect($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toBe(
                '[0:a][1:a]acrossfade=d=0.75[audio];'
                .'[0:v][1:v]xfade=transition=fade:duration=0.75:offset=1.25[operation_video];'
                .'[operation_video]scale=in_range=auto:out_range=tv,format=yuv420p[video]',
            )
            ->and($command)->not->toContain('-vf')
            ->and($command)->toContain('[video]', '[audio]', 'libopenh264');
    });
});

test('chains three inputs through intermediate xfade and acrossfade labels', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $operation = new VideoConcat(
            [
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'first', 2.0)),
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'second', 3.0)),
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'third', 4.0)),
            ],
            VideoConcatMode::Xfade, VideoTransition::Fade, 0.75, true,
            QualityTier::Delivery, 24, $scratch->file('joined.mp4'),
        );

        $command = (new VideoConcatCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-filter_complex', $command, true);

        expect($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toBe(
                '[0:v][1:v]xfade=transition=fade:duration=0.75:offset=1.25[xfade1];'
                .'[0:a][1:a]acrossfade=d=0.75[acrossfade1];'
                .'[acrossfade1][2:a]acrossfade=d=0.75[audio];'
                .'[xfade1][2:v]xfade=transition=fade:duration=0.75:offset=3.5[operation_video];'
                .'[operation_video]scale=in_range=auto:out_range=tv,format=yuv420p[video]',
            );
    });
});

test('compiles hard-cut audio to the shortened xfade timeline', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $operation = new VideoConcat(
            [
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'first', 2.0)),
                fakeRenderedSlateVideo(compilerTestVideo($scratch, 'second', 3.0)),
            ],
            VideoConcatMode::Xfade, VideoTransition::Fade, 0.75, false,
            QualityTier::Delivery, 24, $scratch->file('joined.mp4'),
        );

        $command = (new VideoConcatCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-filter_complex', $command, true);

        expect($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toContain(
                '[0:a]atrim=end=1.25,asetpts=PTS-STARTPTS[hardcut0];'
                .'[1:a]asetpts=PTS-STARTPTS[hardcut1];'
                .'[hardcut0][hardcut1]concat=n=2:v=0:a=1[audio]',
            );
    });
});

test('compiles copy mode with a compiler-owned concat manifest', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $first = fakeRenderedSlateVideo(compilerTestVideo($scratch, 'first', 2.0));
        $second = fakeRenderedSlateVideo(compilerTestVideo($scratch, "second's", 3.0));
        $output = $scratch->file('joined.mp4');
        $operation = new VideoConcat(
            [$first, $second], VideoConcatMode::Copy, null, 0.0, false,
            QualityTier::Intermediate, 24, $output,
        );

        $command = (new VideoConcatCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $inputIndex = array_search('-i', $command, true);
        expect($inputIndex)->toBeInt();
        $manifestPath = $command[$inputIndex + 1];

        expect($command)->toBe([
            '/runtime/ffmpeg/bin/ffmpeg', ...ffmpegInvocationHardeningArguments(),
            '-y', '-f', 'concat', '-safe', '0', ...ffmpegFileInputHardeningArguments(), '-i', $manifestPath,
            ...ffmpegOutputHardeningArguments(),
            '-map', '0:v:0', '-map', '0:a?', '-c', 'copy', $output->writablePath(),
        ])->and(file_get_contents($manifestPath))->toBe(
            "ffconcat version 1.0\nfile '{$first->path->readablePath()}'\nfile '"
            .dirname($second->path->readablePath())."/second'\\''s.mp4'\n",
        );
    });
});
