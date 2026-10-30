<?php

use App\Slate\Driver\Binary\FfmpegLocator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/slate-ffmpeg-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->scratch.'/installed');
    config()->set('slate.ffmpeg.runtime_path', $this->scratch.'/runtime');
    $this->originalPath = (string) getenv('PATH');
});

afterEach(function (): void {
    putenv('PATH='.$this->originalPath);
    File::deleteDirectory($this->scratch);
});

test('links the installed ffmpeg on a platform without a pinned build', function (): void {
    Http::preventStrayRequests();

    foreach (['ffmpeg', 'ffprobe'] as $name) {
        File::put($this->scratch."/installed/{$name}", "#!/bin/sh\n");
        chmod($this->scratch."/installed/{$name}", 0755);
    }
    putenv('PATH='.$this->scratch.'/installed');

    $this->artisan('slate:provision-ffmpeg')->assertSuccessful();

    expect(readlink($this->scratch.'/runtime/ffmpeg/bin/ffmpeg'))->toBe($this->scratch.'/installed/ffmpeg')
        ->and(readlink($this->scratch.'/runtime/ffmpeg/bin/ffprobe'))->toBe($this->scratch.'/installed/ffprobe');
})->skip(fn (): bool => FfmpegLocator::fromSystem()->hasPinnedBuild() || is_executable('/opt/homebrew/bin/ffmpeg') || is_executable('/usr/local/bin/ffmpeg'), 'Runs only where no pinned build or Homebrew ffmpeg shadows the fake binaries.');

test('explains how to install ffmpeg when none is installed', function (): void {
    putenv('PATH='.$this->scratch.'/installed');

    $this->artisan('slate:provision-ffmpeg')
        ->expectsOutputToContain('brew install ffmpeg')
        ->assertFailed();
})->skip(fn (): bool => FfmpegLocator::fromSystem()->hasPinnedBuild() || is_executable('/opt/homebrew/bin/ffmpeg') || is_executable('/usr/local/bin/ffmpeg'), 'Runs only where no pinned build or Homebrew ffmpeg exists.');
