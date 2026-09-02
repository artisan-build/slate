<?php

namespace App\Slate\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ObjectStorage::class,
            fn (): ObjectStorage => new S3ObjectStorage(Storage::disk('s3')),
        );
    }
}
