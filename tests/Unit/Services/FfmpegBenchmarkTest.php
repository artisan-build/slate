<?php

use App\Services\FfmpegBenchmark;

test('summarizes operation throughput against the configured visibility timeout', function (): void {
    $measurements = [
        [
            'operation' => 'video.still_clip',
            'media_duration_ms' => 10000,
            'processing_duration_ms' => 2000,
            'output_size_bytes' => 100,
        ],
        [
            'operation' => 'captions.timed_words',
            'media_duration_ms' => 10000,
            'processing_duration_ms' => 2500,
            'output_size_bytes' => 110,
        ],
        [
            'operation' => 'video.concat',
            'media_duration_ms' => 550250,
            'processing_duration_ms' => 212188,
            'output_size_bytes' => 90,
        ],
    ];

    $summary = FfmpegBenchmark::summarizeMeasurements($measurements, 550250, 220000, 180, 170);

    expect($summary)->toBe([
        'operation_media_ms' => 570250,
        'operation_processing_ms' => 216688,
        'operation_realtime_factor' => 2.632,
        'segment_media_ms' => 10000,
        'segment_processing_ms' => 4500,
        'segment_realtime_factor' => 2.222,
        'caption_media_ms' => 10000,
        'caption_processing_ms' => 2500,
        'caption_realtime_factor' => 4.0,
        'concat_media_ms' => 550250,
        'concat_processing_ms' => 212188,
        'concat_realtime_factor' => 2.593,
        'output_duration_ms' => 550250,
        'total_wall_ms' => 220000,
        'end_to_end_realtime_factor' => 2.501,
        'peak_operation' => 'video.concat',
        'peak_operation_ms' => 212188,
        'driver_timeout_ms' => 170000,
        'driver_headroom_ms' => -42188,
        'visibility_timeout_ms' => 180000,
        'visibility_headroom_ms' => -32188,
    ]);
});
