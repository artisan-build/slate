<?php

use Illuminate\Support\Facades\Storage;

final class FileGetContentsController
{
    public function __invoke(): void
    {
        file_get_contents(Storage::path('source'));
    }
}
