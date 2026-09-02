<?php

declare(strict_types=1);

namespace App\Slate\Driver;

use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\AudioConcatCompiler;
use App\Slate\Driver\Binary\Operation\AudioPadCompiler;
use App\Slate\Driver\Binary\Operation\AudioSilenceCompiler;
use App\Slate\Driver\Binary\Operation\ProbeCompiler;
use App\Slate\Driver\Binary\RecipeCompiler;
use Illuminate\Support\ServiceProvider;

final class DriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            Driver::class,
            fn (): Driver => new BinaryDriver((int) config('slate.driver.timeout_seconds')),
        );
        $this->app->singleton(function (): RecipeCompiler {
            $locator = FfmpegLocator::fromSystem();

            return new RecipeCompiler(
                new ProbeCompiler($locator),
                new AudioSilenceCompiler($locator),
                new AudioConcatCompiler($locator),
                new AudioPadCompiler($locator),
            );
        });
    }
}
