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
    'api' => [
        'result_url_ttl_seconds' => 15 * 60,
        'webhook_connect_timeout_seconds' => 3,
        'webhook_timeout_seconds' => 10,
        // Keep the 10-worker fan-out ceiling: three four-worker runs suggested better one-video latency
        // and drain, but every comparison range overlapped and needs more samples before claiming an effect.
        // Ten workers times ten fair-share waves bounds one run to 100 queued execution jobs.
        'worker_ceiling' => 10,
        'maximum_queue_waves_per_run' => 10,
    ],
    'ingest' => [
        'part_size_bytes' => 8 * 1024 * 1024,
        'maximum_source_bytes' => 8 * 1024 * 1024 * 1024,
        // 8 GiB in 3740s of available work requires 2.19 MiB/s; the remaining 220s cover redelivery.
        'retry_deadline_seconds' => 22 * 180,
    ],
    'input' => [
        'maximum_duration_seconds' => 4 * 60 * 60,
        'maximum_width' => 4096,
        'maximum_height' => 4096,
        'maximum_streams' => 8,
        'maximum_frame_rate' => 60,
        'decode_codecs' => [
            'aac', 'av1', 'flac', 'h264', 'hevc', 'mjpeg', 'mp3', 'mpeg4', 'opus', 'pcm_s16le',
            'pcm_s24le', 'prores', 'vorbis', 'vp8', 'vp9',
        ],
    ],
];
