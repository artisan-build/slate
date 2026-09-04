<?php

use App\Services\FfmpegBenchmark;
use App\Slate\Driver\Binary\FfmpegLocator;

test('runs both strategies through the identical harness and verifies their rendered outputs', function (): void {
    if (! FfmpegLocator::fromSystem()->isProvisioned()) {
        $message = 'Pinned runtime unavailable; realistic benchmark render lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    $result = resolve(FfmpegBenchmark::class)->run('render-test', 6, 3);

    expect(data_get($result, 'workload.width'))->toBe(1536)
        ->and(data_get($result, 'workload.height'))->toBe(1024)
        ->and(data_get($result, 'workload.frames_per_second'))->toBe(24)
        ->and(data_get($result, 'workload.still_supersample'))->toBe(2)
        ->and(data_get($result, 'workload.real_footage_output_size'))->toBe('UNVERIFIED')
        ->and(data_get($result, 'workload.transition_duration_seconds'))->toBe(1.0)
        ->and(data_get($result, 'workload.transition_frame_count'))->toBe(24)
        ->and(data_get($result, 'workload.concat_input_count'))->toBe(3)
        ->and(data_get($result, 'workload.strategy'))->toBe('both')
        ->and(data_get($result, 'workload.hybrid_quality_tier'))->toBe('intermediate')
        ->and(data_get($result, 'workload.intermediate_quality_tier'))->toBe('intermediate')
        ->and(data_get($result, 'workload.production_operation_timeout_seconds'))->toBe(160)
        ->and(data_get($result, 'workload.instrument_operation_timeout_seconds'))->toBe(600)
        ->and(data_get($result, 'workload.caption_word_count'))->toBeGreaterThan(0)
        ->and(data_get($result, 'captions.compiled_drawtext_count'))->toBe(data_get($result, 'workload.caption_word_count'))
        ->and(data_get($result, 'captions.pixels_verified'))->toBeTrue()
        ->and(data_get($result, 'captions.luma_delta'))->toBeGreaterThan(0.1)
        ->and(data_get($result, 'workload'))->not->toHaveKey('final_quality_tier')
        ->and(array_column(data_get($result, 'measurements'), 'operation'))->toBe([
            'audio.silence',
            'video.still_clip',
            'captions.timed_words',
            'overlay.image',
        ]);

    expect(data_get($result, 'strategies.xfade.measurements.0.operation'))->toBe('video.concat')
        ->and(data_get($result, 'strategies.xfade.output_quality_tier'))->toBe('delivery')
        ->and(data_get($result, 'strategies.xfade.output.duration_ms'))->toBe(7000)
        ->and(data_get($result, 'strategies.xfade.output.frame_count'))->toBe(168)
        ->and(data_get($result, 'strategies.xfade.output.published_shape_verified'))->toBeFalse()
        ->and(data_get($result, 'strategies.xfade.output.output_size_bytes'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.xfade.summary.peak_operation_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.xfade.summary.assembly_wall_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.xfade.summary.driver_headroom_ms'))->toBeInt()
        ->and(data_get($result, 'strategies.xfade.summary'))->not->toHaveKey('end_to_end_realtime_factor')
        ->and(data_get($result, 'strategies.xfade.summary'))->not->toHaveKey('total_wall_ms');

    expect(array_column(data_get($result, 'strategies.hybrid.measurements'), 'operation'))->toBe([
        'video.trim',
        'video.transition_segment',
        'video.trim',
        'video.transition_segment',
        'video.trim',
        'video.concat',
    ])
        ->and(data_get($result, 'strategies.hybrid.output_quality_tier'))->toBe('intermediate')
        ->and(data_get($result, 'strategies.hybrid.output.frame_count'))->toBe(168)
        ->and(data_get($result, 'strategies.hybrid.output.duration_ms'))->toBeGreaterThanOrEqual(6000)
        ->and(data_get($result, 'strategies.hybrid.output.duration_ms'))->toBeLessThanOrEqual(8000)
        ->and(data_get($result, 'strategies.hybrid.output.output_size_bytes'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.hybrid.stream_copy.verified'))->toBeTrue()
        ->and(data_get($result, 'strategies.hybrid.stream_copy.verified_copy_operations'))->toBe(4)
        ->and(data_get($result, 'strategies.hybrid.stream_copy.re_encode_operations'))->toBe(2)
        ->and(data_get($result, 'strategies.hybrid.summary.peak_operation_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.hybrid.summary.assembly_wall_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'strategies.hybrid.summary'))->not->toHaveKey('end_to_end_realtime_factor')
        ->and(data_get($result, 'strategies.hybrid.summary'))->not->toHaveKey('total_wall_ms')
        ->and(data_get($result, 'strategies.hybrid.output.frame_count'))->toBe(data_get($result, 'strategies.xfade.output.frame_count'))
        ->and(data_get($result, 'summary.peak_operation_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'summary.total_wall_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'summary.end_to_end_realtime_factor'))->toBeGreaterThan(0);
})->group('ffmpeg-render');

test('rejects workloads whose segments cannot carry a one-second transition body', function (): void {
    expect(fn (): array => resolve(FfmpegBenchmark::class)->run('render-test', 4, 2))
        ->toThrow(RuntimeException::class, 'segments of at least 3 seconds');
})->group('ffmpeg-render');
