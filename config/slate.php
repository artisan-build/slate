<?php

declare(strict_types=1);

return [
    'ffmpeg' => [
        'release_tag' => env('SLATE_FFMPEG_RELEASE_TAG', 'autobuild-2026-09-01-13-13'),
        'asset' => env(
            'SLATE_FFMPEG_ASSET',
            'ffmpeg-n9.0.1-11-ge47273f4d9-linuxarm64-lgpl-9.0.tar.xz',
        ),
        'runtime_path' => env('SLATE_FFMPEG_RUNTIME_PATH', base_path('runtime')),
        'font_path' => env(
            'SLATE_FFMPEG_FONT_PATH',
            resource_path('fonts/NotoSans[wdth,wght].ttf'),
        ),
    ],
    'benchmark' => [
        'duration_seconds' => 540,
        'segment_seconds' => 40,
        'visibility_timeout_seconds' => 180,
        'operation_timeout_seconds' => 600,
        'font_path' => resource_path('fonts/NotoSans[wdth,wght].ttf'),
    ],
    'driver' => [
        'timeout_seconds' => 170,
    ],
    'storage' => [
        'scratch_path' => storage_path('app/slate-jobs'),
    ],
];
