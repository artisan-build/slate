<?php

use Illuminate\Contracts\Filesystem\Filesystem;

final class DownloadController
{
    public function __invoke(Filesystem $storage): void
    {
        $storage->download('source');
    }
}
