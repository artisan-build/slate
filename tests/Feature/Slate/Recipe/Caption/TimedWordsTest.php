<?php

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
use App\Slate\Storage\ScratchDirectory;

test('accepts typed timed words and a staged custom TrueType font', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('image.png'), $scratch->file('audio.mp3'), $scratch->file('source.mp4'),
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $operation = new TimedWords(
            fakeRenderedSlateVideo($source),
            $scratch->file('customer-font.ttf'),
            $scratch->file('captioned.mp4'),
            64,
            CaptionColor::WarmCream,
            new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
            [TimedWord::fromValues("Mia's story", 128, 720, 0.1, 0.2, 0.5, 0.8, 0.2)],
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            new CaptionDim(0.6, 0.1),
        );

        $video = fakeRenderedSlateVideo($operation);

        expect($operation->name()->value)->toBe('captions.timed_words')
            ->and($video->path)->toBe($operation->output)
            ->and($video->durationSeconds)->toBe(2.0);
    });
});

test('rejects caption values outside the audited rendering contract', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = new VideoStillClip(
            $scratch->file('image.png'), $scratch->file('audio.mp3'), $scratch->file('source.mp4'),
            1536, 1024, 24, 2.0, VideoFit::ContainPad, VideoColor::Black, new StillMotion, 1,
            QualityTier::Delivery, AudioBitrate::Kbps192,
        );
        $video = fakeRenderedSlateVideo($source);
        $font = $scratch->file('customer-font.ttf');
        $output = $scratch->file('captioned.mp4');
        $shadow = new CaptionShadow(CaptionShadowColor::Black70, 2, 2);
        $dim = new CaptionDim(0.6, 0.1);
        $word = TimedWord::fromValues('story', 128, 720, 0.1, 0.2, 0.5, 0.8, 0.2);
        $make = static fn (array $words, int $fontSize = 64, ?object $fontOverride = null): TimedWords => new TimedWords(
            $video,
            $fontOverride ?? $font,
            $output,
            $fontSize,
            CaptionColor::WarmCream,
            $shadow,
            $words,
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            $dim,
        );

        expect(fn (): TimedWords => $make([$word], 0))->toThrow(InvalidArgumentException::class, 'font size')
            ->and(fn (): TimedWords => $make([$word], fontOverride: $scratch->file('font.otf')))
            ->toThrow(InvalidArgumentException::class, 'TrueType')
            ->and(fn (): TimedWords => $make([]))->toThrow(InvalidArgumentException::class, 'non-empty ordered list')
            ->and(fn (): TimedWords => $make(['word' => $word]))->toThrow(InvalidArgumentException::class, 'ordered list')
            ->and(fn (): TimedWords => $make([new stdClass]))->toThrow(InvalidArgumentException::class, 'TimedWord')
            ->and(fn (): TimedWords => $make(array_fill(0, 65, $word)))->toThrow(InvalidArgumentException::class, 'at most 64')
            ->and(fn (): TimedWords => $make([
                TimedWord::fromValues('story', 128, 1024, 0.1, 0.2, 0.5, 0.8, 0.2),
            ]))->toThrow(InvalidArgumentException::class, 'within the video frame')
            ->and(fn (): TimedWords => $make([
                TimedWord::fromValues('story', 128, 720, 0.1, 0.2, 0.75, 0.8, 0.2),
            ]))->toThrow(InvalidArgumentException::class, 'dimming must finish')
            ->and(fn (): TimedWords => $make([
                TimedWord::fromValues('story', 128, 720, 0.1, 0.2, 0.5, 1.9, 0.2),
            ]))->toThrow(InvalidArgumentException::class, 'within the video duration')
            ->and(fn (): CaptionDim => new CaptionDim(NAN, 0.1))->toThrow(InvalidArgumentException::class, 'alpha')
            ->and(fn (): CaptionDim => new CaptionDim(0.6, 0.0))->toThrow(InvalidArgumentException::class, 'duration')
            ->and(fn (): CaptionShadow => new CaptionShadow(CaptionShadowColor::Black70, 33, 2))
            ->toThrow(InvalidArgumentException::class, '32 pixels');
    });
});
