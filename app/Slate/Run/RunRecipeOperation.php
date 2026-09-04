<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ObjectKey;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @extends Arrayable<string, mixed> */
interface RunRecipeOperation extends Arrayable, JsonSerializable
{
    public function operation(): OperationName;

    public function qualityTier(): QualityTier;

    public function durationMicroseconds(): int;

    public function audioSource(): ObjectKey;

    public function audioBitrate(): AudioBitrate;
}
