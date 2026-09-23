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
