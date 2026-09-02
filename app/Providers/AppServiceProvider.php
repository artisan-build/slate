<?php

namespace App\Providers;

use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\S3ObjectStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ObjectStorage::class,
            fn (): ObjectStorage => new S3ObjectStorage(Storage::disk('s3')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
