<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FfmpegDiagnostics;
use App\Services\FfmpegLocator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

#[Signature('slate:doctor')]
#[Description('Verify ffmpeg, drawtext rendering, and production-relevant encoders.')]
final class FfmpegDoctorCommand extends Command
{
    public function handle(FfmpegDiagnostics $diagnostics): int
    {
        $locator = FfmpegLocator::fromSystem();
        $checks = [];

        $checks[] = $this->binaryCheck($locator);
        $checks[] = $this->drawtextFilterCheck($locator, $diagnostics);
        $checks[] = $this->fontBuildFlagsCheck($locator, $diagnostics);
        $checks[] = $this->drawtextRenderCheck($locator, $diagnostics);
        $checks[] = $this->encoderCheck($locator, $diagnostics);

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function binaryCheck(FfmpegLocator $locator): bool
    {
        $path = $locator->ffmpegPath();

        if (! is_file($path) || ! is_executable($path)) {
            return $this->check('ffmpeg binary present and executable', false, $path);
        }

        $result = Process::timeout(30)->run([$path, '-hide_banner', '-version']);
        $version = trim(strtok($this->output($result), PHP_EOL) ?: '');

        return $this->check(
            'ffmpeg binary present and executable',
            $result->successful() && $version !== '',
            $path.PHP_EOL.($version !== '' ? $version : trim($result->errorOutput())),
        );
    }

    private function drawtextFilterCheck(FfmpegLocator $locator, FfmpegDiagnostics $diagnostics): bool
    {
        if (! $this->binaryExists($locator)) {
            return $this->check('drawtext filter registered', false, 'ffmpeg binary is unavailable.');
        }

        $result = Process::timeout(30)->run([$locator->ffmpegPath(), '-hide_banner', '-filters']);
        $passed = $result->successful() && $diagnostics->hasDrawtextFilter($this->output($result));

        return $this->check('drawtext filter registered', $passed, $passed ? 'drawtext' : trim($this->output($result)));
    }

    private function fontBuildFlagsCheck(FfmpegLocator $locator, FfmpegDiagnostics $diagnostics): bool
    {
        if (! $this->binaryExists($locator)) {
            return $this->check('font libraries enabled at build time', false, 'ffmpeg binary is unavailable.');
        }

        $result = Process::timeout(30)->run([$locator->ffmpegPath(), '-hide_banner', '-buildconf']);
        $passed = $result->successful() && $diagnostics->hasRequiredFontBuildFlags($this->output($result));
        $detail = '--enable-libfreetype --enable-fontconfig --enable-libharfbuzz --enable-libfribidi';

        return $this->check('font libraries enabled at build time', $passed, $passed ? $detail : trim($this->output($result)));
    }

    private function drawtextRenderCheck(FfmpegLocator $locator, FfmpegDiagnostics $diagnostics): bool
    {
        if (! $this->binaryExists($locator)) {
            return $this->check('drawtext changes rendered pixels', false, 'ffmpeg binary is unavailable.');
        }

        if (! is_file($locator->fontPath())) {
            return $this->check('drawtext changes rendered pixels', false, 'Font is missing: '.$locator->fontPath());
        }

        $blank = $this->renderLuma($locator, null);
        $drawtext = $this->renderLuma($locator, $this->drawtextFilter($locator->fontPath()));
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
            $processErrors = trim($blank->errorOutput().PHP_EOL.$drawtext->errorOutput());
            $details .= $processErrors === '' ? '' : PHP_EOL.$processErrors;
        }

        return $this->check('drawtext changes rendered pixels', $passed, $details);
    }

    private function encoderCheck(FfmpegLocator $locator, FfmpegDiagnostics $diagnostics): bool
    {
        if (! $this->binaryExists($locator)) {
            return $this->check('target encoders inspected', false, 'ffmpeg binary is unavailable.');
        }

        $result = Process::timeout(30)->run([$locator->ffmpegPath(), '-hide_banner', '-encoders']);
        $encoders = $diagnostics->registeredTargetEncoders($this->output($result));
        $details = $encoders === [] ? 'Registered: none' : 'Registered: '.implode(', ', $encoders);

        return $this->check('target encoders inspected', $result->successful(), $details);
    }

    private function renderLuma(FfmpegLocator $locator, ?string $drawtextFilter): ProcessResult
    {
        $filters = array_filter([
            $drawtextFilter,
            'signalstats',
            'metadata=print:key=lavfi.signalstats.YAVG',
        ]);

        return Process::timeout(30)->run([
            $locator->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'info',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=640x200:d=1',
            '-vf',
            implode(',', $filters),
            '-frames:v',
            '1',
            '-f',
            'null',
            '-',
        ]);
    }

    private function drawtextFilter(string $fontPath): string
    {
        $escapedPath = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "\\'"], $fontPath);

        return "drawtext=fontfile='{$escapedPath}':text='slate doctor':fontcolor=white:fontsize=48:x=20:y=60";
    }

    private function binaryExists(FfmpegLocator $locator): bool
    {
        return is_file($locator->ffmpegPath()) && is_executable($locator->ffmpegPath());
    }

    private function output(ProcessResult $result): string
    {
        return $result->output().$result->errorOutput();
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
