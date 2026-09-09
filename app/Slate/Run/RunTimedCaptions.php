<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Caption\CaptionColor;
use App\Slate\Recipe\Caption\CaptionDim;
use App\Slate\Recipe\Caption\CaptionEntryEase;
use App\Slate\Recipe\Caption\CaptionExitEase;
use App\Slate\Recipe\Caption\CaptionShadow;
use App\Slate\Recipe\Caption\CaptionShadowColor;
use App\Slate\Recipe\Caption\TimedWord;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class RunTimedCaptions
{
    /** @var non-empty-list<RunTimedWord> */
    public array $words;

    /** @param array<array-key, mixed> $words */
    public function __construct(
        public ObjectKey $font,
        array $words,
        float $durationSeconds,
        int $width,
        int $height,
    ) {
        if ($words === [] || ! array_is_list($words) || count($words) > 64) {
            throw new InvalidArgumentException('Caption words must be a non-empty ordered list of at most 64 words.');
        }

        foreach ($words as $word) {
            if (! $word instanceof RunTimedWord) {
                throw new InvalidArgumentException('Caption words must contain only typed timed words.');
            }
        }

        $dimDuration = self::dimDuration($words);

        foreach ($words as $word) {
            $word->validateForPage($durationSeconds, $width, $height, $dimDuration);
        }

        $this->words = $words;
    }

    /** @param array<string, mixed> $data */
    public static function fromStoredArray(
        array $data,
        string $runPrefix,
        float $durationSeconds,
        int $width,
        int $height,
    ): self {
        $keys = array_keys($data);
        sort($keys);
        $words = $data['words'] ?? null;

        if ($keys !== ['font_object_key', 'words'] || ! is_string($data['font_object_key'])
            || ! is_array($words) || ! array_is_list($words)) {
            throw new InvalidArgumentException('Caption payload does not match its complete typed schema.');
        }

        return new self(
            RunRecipeObjectKeys::fromStored($runPrefix, $data['font_object_key']),
            array_map(static function (mixed $word): RunTimedWord {
                if (! is_array($word)) {
                    throw new InvalidArgumentException('Caption word payload must be an object.');
                }

                return RunTimedWord::fromArray($word);
            }, $words),
            $durationSeconds,
            $width,
            $height,
        );
    }

    public function localOperation(SlateVideo $input, ScratchPath $font, ScratchPath $output): TimedWords
    {
        $dimDuration = self::dimDuration($this->words);

        return new TimedWords(
            $input,
            $font,
            $output,
            64,
            CaptionColor::WarmCream,
            new CaptionShadow(CaptionShadowColor::Black70, 2, 2),
            array_map(static fn (RunTimedWord $word): TimedWord => $word->local($dimDuration), $this->words),
            CaptionEntryEase::OutBack,
            CaptionExitEase::InOutQuad,
            new CaptionDim(0.6, $dimDuration),
        );
    }

    /** @return array{font_object_key: string, words: non-empty-list<array{text: string, x: int, y: int, start: float, end: float}>} */
    public function toArray(): array
    {
        return [
            'font_object_key' => $this->font->value(),
            'words' => array_map(static fn (RunTimedWord $word): array => $word->toArray(), $this->words),
        ];
    }

    /** @param non-empty-list<RunTimedWord> $words */
    private static function dimDuration(array $words): float
    {
        $shortestWordDuration = min(array_map(
            static fn (RunTimedWord $word): float => $word->end - $word->start,
            $words,
        ));

        return min(0.07, $shortestWordDuration / 4);
    }
}
