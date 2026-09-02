<?php

use App\Slate\Driver\Binary\FilterGraphCompiler;

test('appends normalization to the operation filter chain', function (): void {
    $filter = (new FilterGraphCompiler)->compileH264Video([
        'crop=640:480:0:0',
        'fade=t=out:st=4:d=1',
    ]);

    expect($filter)->toBe(
        'crop=640:480:0:0,fade=t=out:st=4:d=1,scale=in_range=auto:out_range=tv,format=yuv420p',
    );
});

test('escapes drawtext option values for both ffmpeg parser levels', function (): void {
    $escaped = (new FilterGraphCompiler)->escapeOptionValue("it's: 50%\\ready\nnext");

    expect($escaped)->toBe(<<<'ESCAPED'
it\\\'s\\: 50%\\\\ready\\\\nnext
ESCAPED);
});
