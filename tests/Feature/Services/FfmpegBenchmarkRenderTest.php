<?php

use App\Services\FfmpegBenchmark;
use App\Slate\Driver\Binary\FfmpegLocator;

test('runs the realistic workload and verifies its rendered output', function (): void {
    if (! FfmpegLocator::fromSystem()->isProvisioned()) {
        $message = 'Pinned runtime unavailable; realistic benchmark render lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    $result = resolve(FfmpegBenchmark::class)->run('render-test', 2, 1);

    expect(data_get($result, 'workload.width'))->toBe(1536)
        ->and(data_get($result, 'workload.height'))->toBe(1024)
        ->and(data_get($result, 'workload.frames_per_second'))->toBe(24)
        ->and(data_get($result, 'workload.still_supersample'))->toBe(2)
        ->and(data_get($result, 'workload.intermediate_quality_tier'))->toBe('intermediate')
        ->and(data_get($result, 'workload.final_quality_tier'))->toBe('delivery')
        ->and(data_get($result, 'workload.production_operation_timeout_seconds'))->toBe(170)
        ->and(data_get($result, 'workload.instrument_operation_timeout_seconds'))->toBe(600)
        ->and(data_get($result, 'workload.caption_word_count'))->toBeGreaterThan(0)
        ->and(data_get($result, 'captions.compiled_drawtext_count'))->toBe(data_get($result, 'workload.caption_word_count'))
        ->and(data_get($result, 'captions.pixels_verified'))->toBeTrue()
        ->and(data_get($result, 'captions.luma_delta'))->toBeGreaterThan(0.1)
        ->and(data_get($result, 'output.frame_count'))->toBe(data_get($result, 'output.expected_frame_count'))
        ->and(data_get($result, 'output.published_shape_verified'))->toBeFalse()
        ->and(data_get($result, 'output.duration_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'summary.caption_processing_ms'))->toBeGreaterThan(0)
        ->and(data_get($result, 'summary.peak_operation_ms'))->toBeGreaterThan(0)
        ->and(array_column(data_get($result, 'measurements'), 'operation'))->toBe([
            'audio.silence',
            'video.still_clip',
            'captions.timed_words',
            'overlay.image',
            'video.concat',
        ]);
})->group('ffmpeg-render');
