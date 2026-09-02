<?php

namespace App\Providers;

use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Driver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            Driver::class,
            fn (): Driver => new BinaryDriver((int) config('slate.driver.timeout_seconds')),
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
