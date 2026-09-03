<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class BucketVideoTrim implements BucketOperation
{
    public function __construct(
        public int $operationIndex,
        public StoredVideo $input,
        public int $startFrame,
        public int $frameCount,
        public ObjectKey $outputKey,
    ) {
        if ($operationIndex < 0) {
            throw new InvalidArgumentException('Fan-out operation index cannot be negative.');
        }

        if ($startFrame < 0 || $frameCount < 1 || $startFrame + $frameCount > $input->frameCount) {
            throw new InvalidArgumentException('Bucket video trim range must fit within its input.');
        }

        if ($startFrame !== 0
            && ($input->keyframeIntervalFrames === null
                || $startFrame % $input->keyframeIntervalFrames !== 0)) {
            throw new InvalidArgumentException('Bucket video trim must begin on a guaranteed keyframe boundary.');
        }
    }

    public function index(): int
    {
        return $this->operationIndex;
    }

    public function name(): OperationName
    {
        return OperationName::VideoTrim;
    }

    public function inputs(): array
    {
        return [$this->input];
    }

    public function output(): StoredVideo
    {
        return new StoredVideo(
            $this->outputKey,
            $this->input->width,
            $this->input->height,
            $this->input->framesPerSecond,
            $this->frameCount,
            $this->frameCount / $this->input->framesPerSecond,
            $this->input->qualityTier,
            $this->input->keyframeIntervalFrames,
        );
    }

    public function localOperation(array $inputs, ScratchPath $output): VideoTrim
    {
        if (count($inputs) !== 1) {
            throw new InvalidArgumentException('Bucket video trim requires one staged input.');
        }

        return new VideoTrim($inputs[0], $this->startFrame, $this->frameCount, $output);
    }
}
