<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\VideoTransitionSegmentCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function transitionCompilerVideo(ScratchDirectory $scratch, string $name): VideoStillClip
{
    return new VideoStillClip(
        $scratch->file($name.'.png'), $scratch->file($name.'.mp3'), $scratch->file($name.'.mp4'),
        1536, 1024, 24, 3.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
        QualityTier::Delivery, AudioBitrate::Kbps192,
    );
}

test('compiles exact tail and head ranges into one short crossfade', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $from = fakeRenderedSlateVideo(transitionCompilerVideo($scratch, 'from'));
        $to = fakeRenderedSlateVideo(transitionCompilerVideo($scratch, 'to'));
        $operation = new VideoTransitionSegment(
            $from,
            $to,
            24,
            QualityTier::Delivery,
            $scratch->file('transition.mp4'),
        );

        $command = (new VideoTransitionSegmentCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-filter_complex', $command, true);

        expect(array_slice($command, 0, 8))->toBe([
            '/runtime/ffmpeg/bin/ffmpeg', '-y', '-ss', '2', '-i', $from->path->readablePath(),
            '-i', $to->path->readablePath(),
        ])->and($filterIndex)->toBeInt()
            ->and($command[$filterIndex + 1])->toBe(
                '[0:v]trim=end_frame=24,setpts=PTS-STARTPTS[from_video];'
                .'[1:v]trim=end_frame=24,setpts=PTS-STARTPTS[to_video];'
                .'[0:a]atrim=duration=1,asetpts=PTS-STARTPTS[from_audio];'
                .'[1:a]atrim=duration=1,asetpts=PTS-STARTPTS[to_audio];'
                .'[from_audio][to_audio]acrossfade=d=1[audio];'
                .'[from_video][to_video]xfade=transition=fade:duration=1:offset=0[operation_video];'
                .'[operation_video]scale=in_range=auto:out_range=tv,format=yuv420p[video]',
            )->and($command)->toContain('-frames:v', '24', '-t', '1', '-force_key_frames', 'expr:gte(t,n_forced*1)');
    });
});
