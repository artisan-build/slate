<?php

use App\Slate\Driver\Binary\H264RateControl;
use App\Slate\Recipe\QualityTier;

test('computes measured rate control from tier resolution and frame rate', function (
    QualityTier $tier,
    int $width,
    int $height,
    int $framesPerSecond,
    int $targetBitrate,
    int $maximumBitrate,
    int $bufferSize,
): void {
    $arguments = H264RateControl::argumentsFor($tier, $width, $height, $framesPerSecond);

    expect($arguments)->toBe([
        '-c:v',
        H264RateControl::encoder(),
        '-b:v',
        (string) $targetBitrate,
        '-maxrate',
        (string) $maximumBitrate,
        '-bufsize',
        (string) $bufferSize,
        '-profile:v',
        'high',
        '-coder',
        'cabac',
        '-g',
        (string) ($framesPerSecond * 2),
        '-rc_mode',
        'bitrate',
        '-allow_skip_frames',
        '1',
    ]);
})->with([
    'landscape delivery' => [QualityTier::Delivery, 1536, 1024, 24, 1509949, 2264923, 3019898],
    'portrait delivery' => [QualityTier::Delivery, 1024, 1536, 24, 1509949, 2264923, 3019898],
    'landscape intermediate' => [QualityTier::Intermediate, 1536, 1024, 24, 3019899, 4529848, 6039798],
    'different resolution and frame rate' => [QualityTier::Intermediate, 1280, 720, 30, 2211840, 3317760, 4423680],
]);

test('keeps quality tiers distinct and excludes unsupported controls', function (): void {
    $outputs = [];

    foreach (QualityTier::cases() as $tier) {
        $arguments = H264RateControl::argumentsFor($tier, 1536, 1024, 24);
        $serializedArguments = implode(' ', $arguments);

        expect($serializedArguments)
            ->not->toContain('-crf')
            ->not->toContain('-preset')
            ->not->toContain('libx264')
            ->not->toContain('libx265');

        $outputs[] = $arguments;
    }

    expect(array_unique(array_map(serialize(...), $outputs)))->toHaveCount(count(QualityTier::cases()));
});
