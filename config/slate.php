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
        // The instrument must outlive production's kill point to measure the uncensored operation.
        'instrument_operation_timeout_seconds' => 600,
        'font_path' => resource_path('fonts/NotoSans[wdth,wght].ttf'),
    ],
    'execution' => [
        'render_queue' => 'default',
        'ingest_queue' => 'ingest',
        'visibility_window_fallback_seconds' => 180,
        'visibility_window_fallback_seconds_by_queue' => [
            'default' => 180,
            // Intended: 3600. The Cloud CLI cannot set this; raising it requires a Cloud dashboard change.
            'ingest' => 180,
        ],
        'job_timeout_margin_seconds' => 10,
        'driver_timeout_margin_seconds' => 10,
        'output_budget_margin' => 0.8,
        'measured_realtime_rates' => [
            // Intermediate is unmeasured; conservatively use the slower delivery rate below.
            'intermediate' => 0.389,
            // Delivery measured 0.389x realtime at 1536x1024 in PRs #15/#17.
            'delivery' => 0.389,
        ],
    ],
    'storage' => [
        'scratch_path' => storage_path('app/slate-jobs'),
        'allow_host_local_fanout' => env('SLATE_ALLOW_HOST_LOCAL_FANOUT', false),
    ],
    'ingest' => [
        'part_size_bytes' => 8 * 1024 * 1024,
        // 22 attempts x 170s of work exceeds the intended 3600s, across 22 observed 180s windows.
        'retry_deadline_seconds' => 22 * 180,
    ],
];
