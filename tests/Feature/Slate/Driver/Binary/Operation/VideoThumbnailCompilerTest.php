<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\VideoThumbnailCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoThumbnail;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles a poster frame only from a typed Slate output', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $sourcePath = $scratch->file('source.mp4');
        file_put_contents($sourcePath->writablePath(), 'video');
        $source = new VideoStillClip(
            $scratch->file('source.png'), $scratch->file('source.mp3'), $sourcePath,
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $output = $scratch->file('poster.jpg');

        $command = (new VideoThumbnailCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')))
            ->compile(new VideoThumbnail(fakeRenderedSlateVideo($source), $output))
            ->command();

        expect($command)->toBe([
            '/runtime/ffmpeg/bin/ffmpeg', '-y', '-ss', '0', '-i', $sourcePath->readablePath(),
            '-frames:v', '1', '-c:v', 'mjpeg', '-f', 'image2', $output->writablePath(),
        ]);
    });
});

test('rejects a video-container thumbnail output before compilation', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('source.png'), $scratch->file('source.mp3'), $scratch->file('source.mp4'),
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );

        expect(fn (): VideoThumbnail => new VideoThumbnail(
            fakeRenderedSlateVideo($source),
            $scratch->file('poster.mp4'),
        ))->toThrow(InvalidArgumentException::class, 'JPEG');
    });
});
