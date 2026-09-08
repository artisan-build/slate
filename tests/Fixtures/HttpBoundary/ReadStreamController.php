<?php

use Illuminate\Contracts\Filesystem\Filesystem;

final class ReadStreamController
{
    public function __invoke(Filesystem $storage): void
    {
        $storage->readStream('source');
    }
}
