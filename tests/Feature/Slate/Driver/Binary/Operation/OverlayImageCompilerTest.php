<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\OverlayImageCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\OverlayFadeIn;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('compiles overlay and normalization onto one labelled complex graph', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $sourcePath = $scratch->file('source.mp4');
        $badge = $scratch->file('badge.png');
        file_put_contents($sourcePath->writablePath(), 'video');
        file_put_contents($badge->writablePath(), 'image');
        $source = new VideoStillClip(
            $scratch->file('source.png'), $scratch->file('source.mp3'), $sourcePath,
            1536, 1024, 24, 3.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $operation = new OverlayImage(
            fakeRenderedSlateVideo($source), $badge, $scratch->file('badged.mp4'), 20, 40,
            new OverlayFadeIn(1.0, 0.5), 1.0,
        );

        $command = (new OverlayImageCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-filter_complex', $command, true);

        expect($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toBe(
                '[1:v]format=rgba,fade=type=in:st=1:d=0.5:alpha=1[badge];'
                .'[0:v][badge]overlay=x=20:y=40:format=auto:enable=gte(t\\,1)[operation_video];'
                .'[operation_video]scale=in_range=auto:out_range=tv,format=yuv420p[video]',
            )
            ->and($command)->not->toContain('-vf')
            ->and($command)->toContain('[video]', '0:a:0', 'libopenh264');
    });
});
