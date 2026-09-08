<?php

use App\Slate\Driver\Driver;
use App\Slate\Input\InputAdmission;
use App\Slate\Storage\ObjectStorage;

final class ByteMovingController
{
    public function __invoke(InputAdmission $admission, ObjectStorage $storage): void
    {
        resolve(Driver::class);
        $storage->stageIn($objectKey, $scratchPath);
    }
}
