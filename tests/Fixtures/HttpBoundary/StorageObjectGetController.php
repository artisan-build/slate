<?php

use Illuminate\Contracts\Filesystem\Filesystem;

final class StorageObjectGetController
{
    public function __invoke(Filesystem $storage): void
    {
        $storage->get('source');
    }
}
