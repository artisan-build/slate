<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;

final readonly class BucketVideoTransitionSegment implements BucketOperation
{
    public function __construct(
        public int $operationIndex,
        public StoredVideo $from,
        public StoredVideo $to,
        public int $frameCount,
        public QualityTier $qualityTier,
        public ObjectKey $outputKey,
    ) {
        if ($operationIndex < 0) {
            throw new InvalidArgumentException('Fan-out operation index cannot be negative.');
        }

        if ($from->width !== $to->width || $from->height !== $to->height) {
            throw new InvalidArgumentException('Bucket transition inputs must have matching dimensions.');
        }

        if ($from->framesPerSecond !== $to->framesPerSecond) {
            throw new InvalidArgumentException('Bucket transition inputs must have matching frame rates.');
        }

        if ($frameCount < 1 || $frameCount > $from->frameCount || $frameCount > $to->frameCount) {
            throw new InvalidArgumentException('Bucket transition frame count must fit within both inputs.');
        }
    }

    public function index(): int
    {
        return $this->operationIndex;
    }

    public function name(): OperationName
    {
        return OperationName::VideoTransitionSegment;
    }

    public function inputs(): array
    {
        return [$this->from, $this->to];
    }

    public function output(): StoredVideo
    {
        return new StoredVideo(
            $this->outputKey,
            $this->from->width,
            $this->from->height,
            $this->from->framesPerSecond,
            $this->frameCount,
            $this->frameCount / $this->from->framesPerSecond,
            $this->qualityTier,
            $this->from->framesPerSecond,
        );
    }

    public function localOperation(array $inputs, ScratchPath $output): VideoTransitionSegment
    {
        if (count($inputs) !== 2) {
            throw new InvalidArgumentException('Bucket transition segment requires two staged inputs.');
        }

        return new VideoTransitionSegment(
            $inputs[0],
            $inputs[1],
            $this->frameCount,
            $this->qualityTier,
            $output,
        );
    }
}
