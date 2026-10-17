<?php

use App\Slate\Storage\ObjectKey;

test('accepts a relative object key within the caller prefix', function (): void {
    $objectKey = ObjectKey::withinPrefix('customers/customer-123', 'customers/customer-123/inputs/video.mp4');

    expect($objectKey->value())->toBe('customers/customer-123/inputs/video.mp4');
});

test('rejects object keys outside the caller prefix', function (string $prefix, string $key): void {
    expect(fn (): ObjectKey => ObjectKey::withinPrefix($prefix, $key))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'parent traversal' => ['customers/customer-123', 'customers/customer-123/../customer-456/video.mp4'],
    'nested traversal' => ['customers/customer-123', 'customers/customer-123/inputs/../../secret.mp4'],
    'unix absolute path' => ['customers/customer-123', '/customers/customer-123/video.mp4'],
    'windows absolute path' => ['customers/customer-123', 'C:\\customers\\customer-123\\video.mp4'],
    'matching windows drive prefix and key' => ['C:/x', 'C:/x/y'],
    'sibling prefix' => ['customers/customer-123', 'customers/customer-1234/video.mp4'],
    'different caller' => ['customers/customer-123', 'customers/customer-456/video.mp4'],
    'empty key' => ['customers/customer-123', ''],
    'empty segment' => ['customers/customer-123', 'customers/customer-123//video.mp4'],
    'current segment' => ['customers/customer-123', 'customers/customer-123/./video.mp4'],
    'backslash separator' => ['customers/customer-123', 'customers/customer-123\\video.mp4'],
    'control character' => ['customers/customer-123', "customers/customer-123/video\0.mp4"],
    'invalid prefix' => ['../customers/customer-123', 'customers/customer-123/video.mp4'],
]);
