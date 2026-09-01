<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->runtimePath = storage_path('framework/testing/ffmpeg-doctor-'.uniqid());
    $this->fontPath = $this->runtimePath.'/font.ttf';
    $binPath = $this->runtimePath.'/ffmpeg/bin';
    mkdir($binPath, 0755, true);
    file_put_contents($binPath.'/ffmpeg', '#!/bin/sh');
    chmod($binPath.'/ffmpeg', 0755);
    file_put_contents($this->fontPath, 'font fixture');

    config()->set('slate.ffmpeg.runtime_path', $this->runtimePath);
    config()->set('slate.ffmpeg.font_path', $this->fontPath);
});

afterEach(function (): void {
    if (is_dir($this->runtimePath)) {
        File::deleteDirectory($this->runtimePath);
    }
});

test('proves drawtext changed pixels and reports registered encoders', function (): void {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? $process->command : [];

        return match (true) {
            in_array('-version', $command, true) => 'ffmpeg version n9.0.1-11-ge47273f4d9',
            in_array('-filters', $command, true) => ffmpegFixture('ffmpeg-filters.txt'),
            in_array('-buildconf', $command, true) => ffmpegFixture('ffmpeg-buildconf.txt'),
            in_array('-encoders', $command, true) => ffmpegFixture('ffmpeg-encoders.txt'),
            in_array('-vf', $command, true) && str_contains(implode(' ', $command), 'drawtext=fontfile=') => Process::result(
                errorOutput: ffmpegFixture('ffmpeg-drawtext-signalstats.txt'),
            ),
            in_array('-vf', $command, true) => Process::result(
                errorOutput: ffmpegFixture('ffmpeg-blank-signalstats.txt'),
            ),
            default => Process::result(exitCode: 1),
        };
    });

    $this->artisan('slate:doctor')
        ->expectsOutputToContain('PASS ffmpeg binary present and executable')
        ->expectsOutputToContain('PASS drawtext filter registered')
        ->expectsOutputToContain('PASS font libraries enabled at build time')
        ->expectsOutputToContain('PASS drawtext changes rendered pixels')
        ->expectsOutputToContain('blank YAVG=16; drawtext YAVG=20.07')
        ->expectsOutputToContain('Registered: libopenh264, libkvazaar, libsvtav1, libvpx-vp9')
        ->assertSuccessful();
});

test('fails the pixel check when drawtext renders no visible pixels', function (): void {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? $process->command : [];

        return match (true) {
            in_array('-version', $command, true) => 'ffmpeg version n9.0.1-11-ge47273f4d9',
            in_array('-filters', $command, true) => ffmpegFixture('ffmpeg-filters.txt'),
            in_array('-buildconf', $command, true) => ffmpegFixture('ffmpeg-buildconf.txt'),
            in_array('-encoders', $command, true) => ffmpegFixture('ffmpeg-encoders.txt'),
            in_array('-vf', $command, true) => Process::result(
                errorOutput: ffmpegFixture('ffmpeg-blank-signalstats.txt'),
            ),
            default => Process::result(exitCode: 1),
        };
    });

    $this->artisan('slate:doctor')
        ->expectsOutputToContain('FAIL drawtext changes rendered pixels')
        ->assertFailed();
});
