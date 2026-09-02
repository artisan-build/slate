<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class AudioConcat implements Operation
{
    /**
     * @param  non-empty-list<AudioConcatInput|AudioConcatSilence>  $items
     */
    public function __construct(
        public array $items,
        public AudioConcatMode $mode,
        public AudioBitrate $bitrate,
        public ScratchPath $output,
    ) {
        if ($items === [] || ! array_is_list($items)) {
            throw new InvalidArgumentException('Audio concat items must be a non-empty ordered list.');
        }

        foreach ($items as $item) {
            if (! $item instanceof AudioConcatInput && ! $item instanceof AudioConcatSilence) {
                throw new InvalidArgumentException('Audio concat accepts only typed input and silence items.');
            }

            if ($mode === AudioConcatMode::Copy && $item instanceof AudioConcatSilence) {
                throw new InvalidArgumentException('Copy-mode audio concat cannot synthesize inline silence.');
            }
        }
    }

    public function name(): OperationName
    {
        return OperationName::AudioConcat;
    }
}
