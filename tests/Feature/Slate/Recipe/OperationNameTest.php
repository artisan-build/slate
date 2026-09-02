<?php

use App\Slate\Recipe\OperationName;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

test('exposes only the named v1 operation allowlist', function (): void {
    expect(array_column(OperationName::cases(), 'value'))->toBe([
        'probe',
        'audio.silence',
        'audio.concat',
        'audio.pad',
        'video.still_clip',
        'video.concat',
        'overlay.image',
        'captions.timed_words',
        'video.thumbnail',
    ]);
});

test('accepts allowlisted caller values and rejects an unknown operation', function (): void {
    $rules = ['operation' => [Rule::enum(OperationName::class)]];

    $knownOperation = Validator::make(['operation' => 'captions.timed_words'], $rules);
    $unknownOperation = Validator::make(['operation' => 'video.arbitrary'], $rules);

    expect($knownOperation->passes())->toBeTrue()
        ->and($unknownOperation->fails())->toBeTrue();
});
