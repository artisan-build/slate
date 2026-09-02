<?php

use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoThumbnail;

test('cannot accept an arbitrary scratch video as a thumbnail source', function (): void {
    $constructor = new ReflectionMethod(VideoThumbnail::class, '__construct');

    expect($constructor->getParameters()[0]->getType()?->__toString())->toBe(SlateVideo::class)
        ->and(fn (): VideoThumbnail => new VideoThumbnail(new stdClass, new stdClass))
        ->toThrow(TypeError::class);
});
