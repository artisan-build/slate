<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\VideoTrimCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles an exact keyframe seek and frame-limited stream copy', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('source.png'), $scratch->file('source.mp3'), $scratch->file('source.mp4'),
            1536, 1024, 24, 3.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $video = fakeRenderedSlateVideo($source);
        $output = $scratch->file('trimmed.mp4');
        $operation = new VideoTrim($video, 24, 48, $output);

        $command = (new VideoTrimCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
        ))->compile($operation)->command();

        expect($command)->toBe([
            '/runtime/ffmpeg/bin/ffmpeg', ...ffmpegInvocationHardeningArguments(),
            '-y', '-ss', '1', ...ffmpegFileInputHardeningArguments(), '-i', $video->path->readablePath(),
            '-frames:v', '48', ...ffmpegOutputHardeningArguments(), '-t', '2',
            '-c', 'copy', $output->writablePath(),
        ]);
    });
});
