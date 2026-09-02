<?php

use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoThumbnail;

test('requires an execution-probed Slate video as a thumbnail source', function (): void {
    $constructor = new ReflectionMethod(VideoThumbnail::class, '__construct');

    expect($constructor->getParameters()[0]->getType()?->__toString())->toBe(SlateVideo::class)
        ->and(method_exists(VideoStillClip::class, 'video'))->toBeFalse()
        ->and(fn (): VideoThumbnail => new VideoThumbnail(new stdClass, new stdClass))
        ->toThrow(TypeError::class);
});
