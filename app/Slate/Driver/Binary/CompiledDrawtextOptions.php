<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Caption\CaptionColor;
use App\Slate\Recipe\Caption\CaptionDim;
use App\Slate\Recipe\Caption\CaptionEntryEase;
use App\Slate\Recipe\Caption\CaptionExitEase;
use App\Slate\Recipe\Caption\CaptionShadow;
use App\Slate\Recipe\Caption\CaptionShadowColor;
use App\Slate\Recipe\Caption\TimedWord;

/** Compiler-owned drawtext options whose expression strings can only be minted from typed values. */
final readonly class CompiledDrawtextOptions
{
    private const float ExitVisibilityGraceSeconds = 0.1;

    private function __construct(
        private int $fontSize,
        private string $fontColor,
        private string $shadowColor,
        private int $shadowX,
        private int $shadowY,
        private string $alpha,
        private string $x,
        private string $y,
        private string $enable,
        private bool $animated,
    ) {}

    public static function diagnostic(): self
    {
        return new self(48, 'white', 'black@0', 0, 0, '1', '20', '60', '1', false);
    }

    public static function timed(
        TimedWord $word,
        int $fontSize,
        CaptionColor $color,
        CaptionShadow $shadow,
        CaptionEntryEase $entryEase,
        CaptionExitEase $exitEase,
        CaptionDim $dim,
    ): self {
        $lineHeight = (int) ($fontSize * 1.5);
        $entryY = $word->yPixels + (int) ($lineHeight * 0.5);
        $exitY = $word->yPixels - (int) ($lineHeight * 1.5);
        $entryYExpression = match ($entryEase) {
            CaptionEntryEase::OutBack => self::outBackTween(
                $entryY,
                $word->yPixels,
                $word->enterSeconds,
                $word->enterDurationSeconds,
            ),
        };
        $exitYExpression = match ($exitEase) {
            CaptionExitEase::InOutQuad => self::inOutQuadTween(
                $word->yPixels,
                $exitY,
                $word->clearSeconds,
                $word->exitDurationSeconds,
            ),
        };
        $entryAlphaExpression = match ($entryEase) {
            CaptionEntryEase::OutBack => self::outBackTween(
                0,
                1,
                $word->enterSeconds,
                $word->enterDurationSeconds,
            ),
        };
        $dimAlphaExpression = self::outQuadTween(
            1,
            $dim->alpha,
            $word->spokenEndSeconds,
            $dim->durationSeconds,
        );
        $exitAlphaExpression = self::outQuadTween(
            $dim->alpha,
            0,
            $word->clearSeconds,
            $word->exitDurationSeconds,
        );
        $clear = self::number($word->clearSeconds);
        $spokenEnd = self::number($word->spokenEndSeconds);
        $alpha = "if(gt(t\\,{$clear})\\,{$exitAlphaExpression}\\,if(gt(t\\,{$spokenEnd})\\,{$dimAlphaExpression}\\,{$entryAlphaExpression}))";
        $y = "if(gt(t\\,{$clear})\\,{$exitYExpression}\\,{$entryYExpression})";
        $enableEnd = self::number(
            $word->clearSeconds + $word->exitDurationSeconds + self::ExitVisibilityGraceSeconds,
        );
        $enter = self::number($word->enterSeconds);

        return new self(
            $fontSize,
            match ($color) {
                CaptionColor::WarmCream => '0xEAD9BFFF',
            },
            match ($shadow->color) {
                CaptionShadowColor::Black70 => 'black@0.7',
            },
            $shadow->xPixels,
            $shadow->yPixels,
            $alpha,
            (string) $word->xPixels,
            $y,
            "between(t\\,{$enter}\\,{$enableEnd})",
            true,
        );
    }

    public function value(): string
    {
        $staticOptions = 'fontcolor='.$this->fontColor
            .':fontsize='.$this->fontSize;

        if (! $this->animated) {
            return $staticOptions.':x='.$this->x.':y='.$this->y;
        }

        return $staticOptions
            .':shadowcolor='.$this->shadowColor
            .':shadowx='.$this->shadowX
            .':shadowy='.$this->shadowY
            .':alpha='.$this->alpha
            .':x='.$this->x
            .':y='.$this->y
            .':enable='.$this->enable;
    }

    private static function outBackTween(float $from, float $to, float $delay, float $duration): string
    {
        $progress = self::progress($delay, $duration);
        $easing = "1+2.70158*pow(({$progress})-1\\,3)+1.70158*pow(({$progress})-1\\,2)";

        return self::tween($from, $to, $delay, $duration, $easing);
    }

    private static function outQuadTween(float $from, float $to, float $delay, float $duration): string
    {
        $progress = self::progress($delay, $duration);
        $easing = "1-(1-({$progress}))*(1-({$progress}))";

        return self::tween($from, $to, $delay, $duration, $easing);
    }

    private static function inOutQuadTween(float $from, float $to, float $delay, float $duration): string
    {
        $progress = self::progress($delay, $duration);
        $easing = "if(lt({$progress}\\,0.5)\\,2*pow(({$progress})\\,2)\\,1-pow(-2*({$progress})+2\\,2)/2)";

        return self::tween($from, $to, $delay, $duration, $easing);
    }

    private static function tween(float $from, float $to, float $delay, float $duration, string $easing): string
    {
        $fromValue = self::number($from);
        $toValue = self::number($to);
        $delayValue = self::number($delay);
        $endValue = self::number($delay + $duration);

        return "if(lt(t\\,{$delayValue})\\,{$fromValue}\\,if(gt(t\\,{$endValue})\\,{$toValue}\\,{$fromValue}+(({$toValue}-{$fromValue})*({$easing}))))";
    }

    private static function progress(float $delay, float $duration): string
    {
        return '(t-'.self::number($delay).')/'.self::number($duration);
    }

    private static function number(int|float $number): string
    {
        return rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.') ?: '0';
    }
}
