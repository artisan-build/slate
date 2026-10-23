<?php

use App\Slate\Driver\Binary\DiagnosticRecipeCompiler;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;

test('builds info-only diagnostics without output arguments', function (): void {
    $compiler = new DiagnosticRecipeCompiler(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        new FilterGraphCompiler,
    );

    expect([
        'version' => $compiler->version()->command(),
        'filters' => $compiler->filters()->command(),
        'build configuration' => $compiler->buildConfiguration()->command(),
        'encoders' => $compiler->encoders()->command(),
    ])->toBe([
        'version' => [
            '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-protocol_whitelist', 'file', '-hide_banner', '-version',
        ],
        'filters' => [
            '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-protocol_whitelist', 'file', '-hide_banner', '-filters',
        ],
        'build configuration' => [
            '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-protocol_whitelist', 'file', '-hide_banner', '-buildconf',
        ],
        'encoders' => [
            '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-protocol_whitelist', 'file', '-hide_banner', '-encoders',
        ],
    ]);
});

test('builds the luma diagnostic recipe with and without a diagnostic drawtext fragment', function (): void {
    $compiler = new DiagnosticRecipeCompiler(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        new FilterGraphCompiler,
    );

    expect($compiler->luma('test-font.ttf')->command())->toBe([
        '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'info', '-f', 'lavfi',
        '-protocol_whitelist', 'file', '-i', 'color=c=black:s=640x200:d=1', '-vf',
        'drawtext=fontfile=test-font.ttf:text=slate doctor:expansion=none:fontcolor=white:fontsize=48:x=20:y=60,signalstats,metadata=print:key=lavfi.signalstats.YAVG',
        '-frames:v', '1', '-map_metadata', '-1', '-t', '86400', '-fs', '17179869184', '-f', 'null', '-',
    ])->and($compiler->luma(null)->command())->toBe([
        '/runtime/ffmpeg/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'info', '-f', 'lavfi',
        '-protocol_whitelist', 'file', '-i', 'color=c=black:s=640x200:d=1', '-vf',
        'signalstats,metadata=print:key=lavfi.signalstats.YAVG',
        '-frames:v', '1', '-map_metadata', '-1', '-t', '86400', '-fs', '17179869184', '-f', 'null', '-',
    ]);
});
