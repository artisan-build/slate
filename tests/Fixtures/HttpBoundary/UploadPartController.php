<?php

use App\Slate\Storage\IngestStorage;

final class UploadPartController
{
    public function __invoke(IngestStorage $storage): void
    {
        $storage->uploadPart($key, 'upload', 1, $stream, 10);
    }
}
