<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FfmpegBenchmark;
use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use Illuminate\Support\ServiceProvider;

final class DriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            Driver::class,
            fn (): Driver => new BinaryDriver((int) config('slate.driver.timeout_seconds')),
        );
        $this->app->singleton(fn (): RecipeCompiler => RecipeCompiler::fromSystem());
        $this->app->when(FfmpegBenchmark::class)
            ->needs(Driver::class)
            ->give(fn (): Driver => new BinaryDriver((int) config('slate.benchmark.operation_timeout_seconds')));
    }
}
