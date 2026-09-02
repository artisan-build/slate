<?php

use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\H264OutputContract;
use App\Slate\Recipe\QualityTier;

test('computes measured rate control from tier resolution and frame rate', function (
    QualityTier $tier,
    int $width,
    int $height,
    int $framesPerSecond,
    int $requestedBitrate,
): void {
    $arguments = H264OutputContract::argumentsFor($tier, $width, $height, $framesPerSecond);

    expect($arguments)->toBe([
        '-c:v',
        H264OutputContract::encoder(),
        '-b:v',
        (string) $requestedBitrate,
        '-profile:v',
        'high',
        '-coder',
        'cabac',
        '-g',
        (string) ($framesPerSecond * 2),
        '-rc_mode',
        'bitrate',
        '-pix_fmt',
        'yuv420p',
        CompiledOption::ColorRange->value,
        'tv',
        '-r',
        (string) $framesPerSecond,
        '-c:a',
        'aac',
        '-b:a',
        '192k',
    ]);
})->with([
    'landscape delivery' => [QualityTier::Delivery, 1536, 1024, 24, 1509949],
    'portrait delivery' => [QualityTier::Delivery, 1024, 1536, 24, 1509949],
    'smaller delivery' => [QualityTier::Delivery, 1280, 720, 30, 1105920],
    'landscape intermediate' => [QualityTier::Intermediate, 1536, 1024, 24, 6039798],
    'portrait intermediate' => [QualityTier::Intermediate, 1024, 1536, 24, 6039798],
    'smaller intermediate' => [QualityTier::Intermediate, 1280, 720, 30, 4423680],
]);

test('keeps quality tiers distinct and excludes unsupported controls', function (): void {
    $outputs = [];

    foreach (QualityTier::cases() as $tier) {
        $arguments = H264OutputContract::argumentsFor($tier, 1536, 1024, 24);
        $serializedArguments = implode(' ', $arguments);

        expect($serializedArguments)
            ->not->toContain('-crf')
            ->not->toContain('-preset')
            ->not->toContain('libx264')
            ->not->toContain('libx265')
            ->not->toContain('-maxrate')
            ->not->toContain('-bufsize')
            ->not->toContain('-allow_skip_frames')
            ->not->toContain('-vf');

        $outputs[] = $arguments;
    }

    expect(array_unique(array_map(serialize(...), $outputs)))->toHaveCount(count(QualityTier::cases()));
});

test('provides normalization as an append-only filter fragment', function (): void {
    $operationFilter = 'crop=640:480:0:0';

    $composedFilter = $operationFilter.','.H264OutputContract::videoNormalizationFilter();

    expect($composedFilter)->toBe(
        'crop=640:480:0:0,scale=in_range=auto:out_range=tv,format=yuv420p',
    );
});
