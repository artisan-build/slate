<?php

use App\Services\FfmpegLocator;
use Tests\TestCase;

uses(TestCase::class);

test('resolves the pinned LGPL asset for Linux arm64', function (string $machine) {
    config()->set('slate.ffmpeg.asset', 'ffmpeg-linuxarm64-lgpl.tar.xz');

    $asset = (new FfmpegLocator('Linux', $machine))->assetName();

    expect($asset)->toBe('ffmpeg-linuxarm64-lgpl.tar.xz');
})->with(['arm64', 'aarch64']);

test('rejects an unsupported platform', function (string $osFamily, string $machine) {
    $resolve = fn (): string => (new FfmpegLocator($osFamily, $machine))->assetName();

    expect($resolve)->toThrow(
        RuntimeException::class,
        "Unsupported ffmpeg platform [{$osFamily}/{$machine}].",
    );
})->with([
    ['Darwin', 'arm64'],
    ['Linux', 'x86_64'],
    ['Windows', 'AMD64'],
]);

test('keeps binaries inside the configured app runtime path', function () {
    config()->set('slate.ffmpeg.runtime_path', '/var/www/html/runtime');

    $locator = new FfmpegLocator('Linux', 'aarch64');

    expect($locator->ffmpegPath())->toBe('/var/www/html/runtime/ffmpeg/bin/ffmpeg')
        ->and($locator->ffprobePath())->toBe('/var/www/html/runtime/ffmpeg/bin/ffprobe');
});
