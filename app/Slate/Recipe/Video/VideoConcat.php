<?php

declare(strict_types=1);

namespace App\Slate\Recipe\Video;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class VideoConcat implements Operation
{
    /** @var non-empty-list<SlateVideo> */
    public array $inputs;

    /**
     * @param  array<array-key, mixed>  $inputs
     */
    public function __construct(
        array $inputs,
        public VideoConcatMode $mode,
        public ?VideoTransition $transition,
        public float $transitionDurationSeconds,
        public bool $audioCrossfade,
        public QualityTier $qualityTier,
        public int $framesPerSecond,
        public ScratchPath $output,
    ) {
        if (count($inputs) < 2 || ! array_is_list($inputs)) {
            throw new InvalidArgumentException('Video concat inputs must be an ordered list of at least two Slate outputs.');
        }

        $validatedInputs = [];

        foreach ($inputs as $input) {
            if (! $input instanceof SlateVideo) {
                throw new InvalidArgumentException('Video concat accepts only typed Slate video outputs.');
            }

            $validatedInputs[] = $input;

            if ($input->width !== $inputs[0]->width || $input->height !== $inputs[0]->height) {
                throw new InvalidArgumentException('Video concat inputs must have matching dimensions.');
            }

            if ($input->framesPerSecond !== $framesPerSecond) {
                throw new InvalidArgumentException('Video concat inputs must match the requested frame rate.');
            }
        }

        $this->inputs = $validatedInputs;

        if ($framesPerSecond !== 24) {
            throw new InvalidArgumentException('Video concat frame rate must match the audited 24 fps output contract.');
        }

        if ($mode === VideoConcatMode::Copy) {
            if ($transition !== null || $transitionDurationSeconds !== 0.0 || $audioCrossfade) {
                throw new InvalidArgumentException('Copy-mode video concat cannot define transition behavior.');
            }

            foreach ($this->inputs as $input) {
                if ($input->qualityTier !== $qualityTier) {
                    throw new InvalidArgumentException('Copy-mode video concat inputs must match the output quality tier.');
                }
            }

            return;
        }

        if ($transition !== VideoTransition::Fade
            || ! AudioDuration::isPositiveMicrosecondDuration($transitionDurationSeconds)) {
            throw new InvalidArgumentException('Xfade video concat requires a positive fade transition duration.');
        }

        foreach ($this->inputs as $input) {
            if ($input->durationSeconds <= $transitionDurationSeconds) {
                throw new InvalidArgumentException('Every video concat input must be longer than the transition.');
            }
        }

        if (! AudioDuration::isPositiveMicrosecondDuration($this->outputDurationSeconds())) {
            throw new InvalidArgumentException('Computed video concat duration is outside compiler precision or range.');
        }
    }

    public function name(): OperationName
    {
        return OperationName::VideoConcat;
    }

    public function width(): int
    {
        return $this->inputs[0]->width;
    }

    public function height(): int
    {
        return $this->inputs[0]->height;
    }

    public function outputDurationSeconds(): float
    {
        $duration = array_sum(array_map(
            static fn (SlateVideo $input): float => $input->durationSeconds,
            $this->inputs,
        ));

        if ($this->mode === VideoConcatMode::Xfade) {
            $duration -= $this->transitionDurationSeconds * (count($this->inputs) - 1);
        }

        return $duration;
    }

    public function video(): SlateVideo
    {
        return SlateVideo::fromConcat($this);
    }
}
