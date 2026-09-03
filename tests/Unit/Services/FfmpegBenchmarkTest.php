<?php

use App\Services\FfmpegBenchmark;

test('summarizes one strategy over its own operations only', function (): void {
    $measurements = [
        [
            'operation' => 'video.trim',
            'media_duration_ms' => 39000,
            'processing_duration_ms' => 47,
            'output_size_bytes' => 100,
        ],
        [
            'operation' => 'video.transition_segment',
            'media_duration_ms' => 1000,
            'processing_duration_ms' => 508,
            'output_size_bytes' => 110,
        ],
        [
            'operation' => 'video.concat',
            'media_duration_ms' => 547000,
            'processing_duration_ms' => 316,
            'output_size_bytes' => 90,
        ],
    ];

    $summary = FfmpegBenchmark::summarizeStrategy($measurements, 7607, 180, 170);

    expect($summary)->toBe([
        'assembly_wall_ms' => 7607,
        'strategy_media_ms' => 587000,
        'strategy_processing_ms' => 871,
        'strategy_realtime_factor' => 673.938,
        'peak_operation' => 'video.transition_segment',
        'peak_operation_ms' => 508,
        'driver_timeout_ms' => 170000,
        'driver_headroom_ms' => 169492,
        'visibility_timeout_ms' => 180000,
        'visibility_headroom_ms' => 179492,
    ]);
});

test('never pairs a peak with a wall that excludes it', function (): void {
    $measurements = [
        [
            'operation' => 'video.concat',
            'media_duration_ms' => 430000,
            'processing_duration_ms' => 161871,
            'output_size_bytes' => 90,
        ],
    ];

    $summary = FfmpegBenchmark::summarizeStrategy($measurements, 161871, 180, 170);

    expect($summary)->not->toHaveKey('end_to_end_realtime_factor')
        ->and($summary)->not->toHaveKey('total_wall_ms')
        ->and($summary['peak_operation_ms'])->toBeLessThanOrEqual($summary['assembly_wall_ms']);
});

test('pins the published default output duration and frame count', function (): void {
    expect(FfmpegBenchmark::matchesPublishedOutputShape(547000, 13128))->toBeTrue()
        ->and(FfmpegBenchmark::matchesPublishedOutputShape(550250, 13206))->toBeFalse()
        ->and(FfmpegBenchmark::matchesPublishedOutputShape(546000, 13128))->toBeFalse()
        ->and(FfmpegBenchmark::matchesPublishedOutputShape(547000, 13127))->toBeFalse();
});
