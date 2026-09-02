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
            'media_duration_ms' => 90000,
            'processing_duration_ms' => 12000,
            'output_size_bytes' => 90,
        ],
    ];

    $summary = FfmpegBenchmark::summarizeMeasurements($measurements, 90000, 18000, 180);

    expect($summary)->toBe([
        'operation_media_ms' => 110000,
        'operation_processing_ms' => 16500,
        'operation_realtime_factor' => 6.667,
        'segment_media_ms' => 10000,
        'segment_processing_ms' => 4500,
        'segment_realtime_factor' => 2.222,
        'caption_media_ms' => 10000,
        'caption_processing_ms' => 2500,
        'caption_realtime_factor' => 4.0,
        'concat_media_ms' => 90000,
        'concat_processing_ms' => 12000,
        'concat_realtime_factor' => 7.5,
        'output_duration_ms' => 90000,
        'total_wall_ms' => 18000,
        'end_to_end_realtime_factor' => 5.0,
        'peak_operation' => 'video.concat',
        'peak_operation_ms' => 12000,
        'visibility_timeout_ms' => 180000,
        'visibility_headroom_ms' => 168000,
    ]);
});
