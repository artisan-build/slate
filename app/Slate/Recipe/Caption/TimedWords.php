<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Caption;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class TimedWords implements Operation
{
    private const array FontSignatures = ["\x00\x01\x00\x00", 'true', 'ttcf', 'OTTO'];

    private const int MaxWords = 64;

    /**
     * @param  non-empty-list<TimedWord>  $words
     */
    public function __construct(
        public SlateVideo $input,
        public ScratchPath $font,
        public ScratchPath $output,
        public int $fontSize,
        public CaptionColor $color,
        public CaptionShadow $shadow,
        public array $words,
        public CaptionEntryEase $entryEase,
        public CaptionExitEase $exitEase,
        public CaptionDim $dim,
    ) {
        if ($fontSize < 1 || $fontSize > 256) {
            throw new InvalidArgumentException('Caption font size must be between 1 and 256 pixels.');
        }

        if (preg_match('/\.(?:ttf|ttc|otf)$/i', $font->writablePath()) !== 1) {
            throw new InvalidArgumentException('Caption fonts must be staged TrueType or OpenType font files.');
        }

        $signature = file_get_contents($font->readablePath(), false, null, 0, 4);

        if (! is_string($signature) || ! in_array($signature, self::FontSignatures, true)) {
            throw new InvalidArgumentException('Caption font must have a recognized TrueType or OpenType sfnt signature.');
        }

        if ($words === [] || ! array_is_list($words) || count($words) > self::MaxWords) {
            throw new InvalidArgumentException('Caption words must be a non-empty ordered list of at most 64 words.');
        }

        foreach ($words as $word) {
            if (! $word instanceof TimedWord) {
                throw new InvalidArgumentException('Caption words must contain only TimedWord values.');
            }

            if ($word->xPixels >= $input->width || $word->yPixels >= $input->height) {
                throw new InvalidArgumentException('Timed word coordinates must begin within the video frame.');
            }

            if ($word->spokenEndSeconds + $dim->durationSeconds > $word->clearSeconds) {
                throw new InvalidArgumentException('Timed word dimming must finish before its clear time.');
            }

            if ($word->clearSeconds + $word->exitDurationSeconds > $input->durationSeconds) {
                throw new InvalidArgumentException('Timed word animation must finish within the video duration.');
            }
        }
    }

    public function name(): OperationName
    {
        return OperationName::CaptionsTimedWords;
    }
}
