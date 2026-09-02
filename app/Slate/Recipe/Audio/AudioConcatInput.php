<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Audio;

use App\Slate\Storage\ScratchPath;

final readonly class AudioConcatInput
{
    public function __construct(public ScratchPath $input) {}
}
