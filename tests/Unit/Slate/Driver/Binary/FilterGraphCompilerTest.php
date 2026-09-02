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

test('escapes diagnostic font paths inside the compiler boundary', function (): void {
    $filter = (new FilterGraphCompiler)->compileDiagnosticDrawtext("C:\\fonts\\Mia's.ttf");

    expect($filter)->toBe(
        "drawtext=fontfile='C\\:\\\\fonts\\\\Mia\\'s.ttf':text='slate doctor':fontcolor=white:fontsize=48:x=20:y=60",
    );
});
