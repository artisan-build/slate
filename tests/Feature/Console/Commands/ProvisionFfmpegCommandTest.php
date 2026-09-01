<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->runtimePath = storage_path('framework/testing/ffmpeg-provision-'.uniqid());
    config()->set('slate.ffmpeg.runtime_path', $this->runtimePath);
});

afterEach(function (): void {
    if (is_dir($this->runtimePath)) {
        File::deleteDirectory($this->runtimePath);
    }
});

test('does not download or extract an existing installation', function (): void {
    $binPath = $this->runtimePath.'/ffmpeg/bin';
    mkdir($binPath, 0755, true);

    foreach (['ffmpeg', 'ffprobe'] as $binary) {
        file_put_contents($binPath.'/'.$binary, '#!/bin/sh');
        chmod($binPath.'/'.$binary, 0755);
    }

    Http::preventStrayRequests();
    Process::preventStrayProcesses();

    $this->artisan('slate:provision-ffmpeg')
        ->expectsOutputToContain('ffmpeg is already present')
        ->assertSuccessful();
});

test('fails clearly before downloading when xz is unavailable', function (): void {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => $process->command === ['xz', '--version']
        ? Process::result(exitCode: 127)
        : Process::result(exitCode: 1));

    $this->artisan('slate:provision-ffmpeg')
        ->expectsOutputToContain('Missing required `xz` executable')
        ->assertFailed();
});
