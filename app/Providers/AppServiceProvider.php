<?php

namespace App\Providers;

use App\Slate\Ingest\HttpSourceReader;
use App\Slate\Ingest\SourceReader;
use App\Slate\Webhook\HostnameResolver;
use App\Slate\Webhook\SystemHostnameResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SourceReader::class, HttpSourceReader::class);
        $this->app->bind(HostnameResolver::class, SystemHostnameResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
