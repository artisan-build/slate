<?php

use App\Services\FfmpegBenchmark;

test('summarizes chunk throughput against the configured visibility timeout', function () {
    $worker = [
        'hostname' => 'worker-a',
        'memory_max' => '2147483648',
        'memory_bytes' => 2147483648,
        'cpu_max' => '100000 100000',
        'cpu_cores' => 1.0,
    ];
    $measurements = [
        [
            'chunk_index' => 0,
            'media_duration_ms' => 10000,
            'processing_duration_ms' => 2000,
            'output_size_bytes' => 100,
            'worker' => $worker,
        ],
        [
            'chunk_index' => 1,
            'media_duration_ms' => 10000,
            'processing_duration_ms' => 2500,
            'output_size_bytes' => 110,
            'worker' => $worker,
        ],
        [
            'chunk_index' => 2,
            'media_duration_ms' => 10000,
            'processing_duration_ms' => 1500,
            'output_size_bytes' => 90,
            'worker' => $worker,
        ],
    ];

    $summary = (new FfmpegBenchmark)->summarizeMeasurements($measurements, 1000, 13000, 180);

    expect($summary)->toBe([
        'media_duration_ms' => 30000,
        'chunk_processing_ms' => 6000,
        'total_wall_ms' => 12000,
        'encoding_realtime_factor' => 5.0,
        'end_to_end_realtime_factor' => 2.5,
        'peak_chunk_ms' => 2500,
        'visibility_timeout_ms' => 180000,
        'visibility_headroom_ms' => 177500,
    ]);
});
