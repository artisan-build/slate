<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\Binary\Operation\TimedWordsCompiler;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Caption\CaptionColor;
use App\Slate\Recipe\Caption\CaptionDim;
use App\Slate\Recipe\Caption\CaptionEntryEase;
use App\Slate\Recipe\Caption\CaptionExitEase;
use App\Slate\Recipe\Caption\CaptionShadow;
use App\Slate\Recipe\Caption\CaptionShadowColor;
use App\Slate\Recipe\Caption\TimedWord;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Support\Facades\Storage;

test('compiles staged font text and typed keyframes into one normalized H264 graph', function (): void {
    Storage::fake();
    $fontKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/fonts/story.ttf');
    Storage::disk()->put($fontKey->value(), "\x00\x01\x00\x00");

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($fontKey): void {
        $source = new VideoStillClip(
            $scratch->file('image.png'), $scratch->file('audio.mp3'), $scratch->file('source.mp4'),
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $video = fakeRenderedSlateVideo($source);
        $font = $scratch->file('customer-font.ttf');
        resolve(ObjectStorage::class)->stageIn($fontKey, $font);
        $operation = new TimedWords(
            $video,
            $font,
            $scratch->file('captioned.mp4'),
            64,
            CaptionColor::WarmCream,
            new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
            [
                TimedWord::fromValues("Mia's: [50%]\\ready,; %{pts}", 128, 720, 0.1, 0.2, 0.5, 0.8, 0.2),
                TimedWord::fromValues('again', 420, 720, 0.2, 0.2, 0.55, 0.9, 0.2),
            ],
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            new CaptionDim(0.6, 0.1),
        );

        $command = (new TimedWordsCompiler(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            new FilterGraphCompiler,
        ))->compile($operation)->command();
        $filterIndex = array_search('-vf', $command, true);

        expect($filterIndex)->toBeInt();
        $filter = $command[$filterIndex + 1];

        expect(array_keys($command, '-i', true))->toHaveCount(1)
            ->and($command[$filterIndex + 1])->toBeString()
            ->and($filter)->toContain('drawtext=fontfile='.$font->readablePath().':text=')
            ->and($filter)->not->toContain('font=')
            ->and($filter)->toContain(<<<'ESCAPED'
text=Mia\\\'s\\: \[50%\]\\\\ready\,\; %{pts}:expansion=none
ESCAPED)
            ->and($filter)->toContain(':x=128:')
            ->and($filter)->toContain('2.70158*pow(((t-0.1)/0.2)-1\,3)')
            ->and($filter)->toContain('if(lt((t-0.8)/0.2\,0.5)')
            ->and($filter)->toContain(':enable=between(t\,0.1\,1.1)')
            ->and(substr_count($filter, 'drawtext='))->toBe(2)
            ->and($filter)->toEndWith('scale=in_range=auto:out_range=tv,format=yuv420p')
            ->and($command)->toContain('0:v:0', '0:a:0', 'libopenh264', $operation->output->writablePath());

    });
});
