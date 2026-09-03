<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\ScratchPath;

interface BucketOperation
{
    public function index(): int;

    public function name(): OperationName;

    /** @return non-empty-list<StoredVideo> */
    public function inputs(): array;

    public function output(): StoredVideo;

    /**
     * @param  non-empty-list<SlateVideo>  $inputs
     */
    public function localOperation(array $inputs, ScratchPath $output): VideoTrim|VideoTransitionSegment;
}
