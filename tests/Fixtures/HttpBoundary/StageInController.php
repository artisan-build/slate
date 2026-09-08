<?php

use App\Slate\Storage\ObjectStorage;

final class StageInController
{
    public function __invoke(ObjectStorage $storage): void
    {
        $storage->stageIn($objectKey, $scratchPath);
    }
}
