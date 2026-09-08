<?php

use Illuminate\Support\Facades\Storage;

final class StorageFacadeGetController
{
    public function __invoke(): void
    {
        Storage::disk()->get('source');
    }
}
