<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FfmpegDiagnostics;
use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Binary\DiagnosticRecipeCompiler;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\DriverResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slate:doctor')]
#[Description('Verify ffmpeg, drawtext rendering, and production-relevant encoders.')]
final class FfmpegDoctorCommand extends Command
{
    public function handle(FfmpegDiagnostics $diagnostics): int
    {
        $locator = FfmpegLocator::fromSystem();
        $driver = new BinaryDriver(30);
        $compiler = new DiagnosticRecipeCompiler($locator, new FilterGraphCompiler);
        $checks = [];

        $checks[] = $this->binaryCheck($locator, $driver, $compiler);
        $checks[] = $this->drawtextFilterCheck($locator, $driver, $compiler, $diagnostics);
        $checks[] = $this->fontBuildFlagsCheck($locator, $driver, $compiler, $diagnostics);
        $checks[] = $this->drawtextRenderCheck($locator, $driver, $compiler, $diagnostics);
        $checks[] = $this->encoderCheck($locator, $driver, $compiler, $diagnostics);

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function binaryCheck(
        FfmpegLocator $locator,
        BinaryDriver $driver,
        DiagnosticRecipeCompiler $compiler,
    ): bool {
        $path = $locator->ffmpegPath();

        if (! is_file($path) || ! is_executable($path)) {
            return $this->check('ffmpeg binary present and executable', false, $path);
        }

        $result = $driver->run($compiler->version());
        $version = trim(strtok($result->output.$result->errorOutput, PHP_EOL) ?: '');

        return $this->check(
            'ffmpeg binary present and executable',
            $result->successful() && $version !== '',
            $path.PHP_EOL.($version !== '' ? $version : trim($result->errorOutput)),
        );
    }

    private function drawtextFilterCheck(
        FfmpegLocator $locator,
        BinaryDriver $driver,
        DiagnosticRecipeCompiler $compiler,
        FfmpegDiagnostics $diagnostics,
    ): bool {
        if (! $this->binaryExists($locator)) {
            return $this->check('drawtext filter registered', false, 'ffmpeg binary is unavailable.');
        }

        $result = $driver->run($compiler->filters());
        $passed = $result->successful() && $diagnostics->hasDrawtextFilter($this->output($result));

        return $this->check('drawtext filter registered', $passed, $passed ? 'drawtext' : trim($this->output($result)));
    }

    private function fontBuildFlagsCheck(
        FfmpegLocator $locator,
        BinaryDriver $driver,
        DiagnosticRecipeCompiler $compiler,
        FfmpegDiagnostics $diagnostics,
    ): bool {
        if (! $this->binaryExists($locator)) {
            return $this->check('font libraries enabled at build time', false, 'ffmpeg binary is unavailable.');
        }

        $result = $driver->run($compiler->buildConfiguration());
        $passed = $result->successful() && $diagnostics->hasRequiredFontBuildFlags($this->output($result));
        $detail = '--enable-libfreetype --enable-fontconfig --enable-libharfbuzz --enable-libfribidi';

        return $this->check('font libraries enabled at build time', $passed, $passed ? $detail : trim($this->output($result)));
    }

    private function drawtextRenderCheck(
        FfmpegLocator $locator,
        BinaryDriver $driver,
        DiagnosticRecipeCompiler $compiler,
        FfmpegDiagnostics $diagnostics,
    ): bool {
        if (! $this->binaryExists($locator)) {
            return $this->check('drawtext changes rendered pixels', false, 'ffmpeg binary is unavailable.');
        }

        if (! is_file($locator->fontPath())) {
            return $this->check('drawtext changes rendered pixels', false, 'Font is missing: '.$locator->fontPath());
        }

        $blank = $driver->run($compiler->luma(null));
        $drawtext = $driver->run($compiler->luma($locator->fontPath()));
        $blankLuma = $blank->successful() ? $diagnostics->averageLuma($this->output($blank)) : null;
        $drawtextLuma = $drawtext->successful() ? $diagnostics->averageLuma($this->output($drawtext)) : null;
        $passed = $diagnostics->pixelsChanged($blankLuma, $drawtextLuma);
        $details = sprintf(
            'fontfile=%s; blank YAVG=%s; drawtext YAVG=%s',
            $locator->fontPath(),
            $blankLuma === null ? 'unavailable' : (string) $blankLuma,
            $drawtextLuma === null ? 'unavailable' : (string) $drawtextLuma,
        );

        if (! $passed) {
            $processErrors = trim($blank->errorOutput.PHP_EOL.$drawtext->errorOutput);
            $details .= $processErrors === '' ? '' : PHP_EOL.$processErrors;
        }

        return $this->check('drawtext changes rendered pixels', $passed, $details);
    }

    private function encoderCheck(
        FfmpegLocator $locator,
        BinaryDriver $driver,
        DiagnosticRecipeCompiler $compiler,
        FfmpegDiagnostics $diagnostics,
    ): bool {
        if (! $this->binaryExists($locator)) {
            return $this->check('target encoders inspected', false, 'ffmpeg binary is unavailable.');
        }

        $result = $driver->run($compiler->encoders());
        $encoders = $diagnostics->registeredTargetEncoders($this->output($result));
        $details = $encoders === [] ? 'Registered: none' : 'Registered: '.implode(', ', $encoders);

        return $this->check('target encoders inspected', $result->successful(), $details);
    }

    private function binaryExists(FfmpegLocator $locator): bool
    {
        return is_file($locator->ffmpegPath()) && is_executable($locator->ffmpegPath());
    }

    private function output(DriverResult $result): string
    {
        return $result->output.$result->errorOutput;
    }

    private function check(string $label, bool $passed, string $details): bool
    {
        $this->line(($passed ? 'PASS' : 'FAIL').' '.$label);

        if ($details !== '') {
            $this->line($details);
        }

        return $passed;
    }
}
