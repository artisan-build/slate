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
    $escaped = (new FilterGraphCompiler)->escapeOptionValue("it's: 50%\\ready");

    expect($escaped)->toBe(<<<'ESCAPED'
it\\\'s\\: 50%\\\\ready
ESCAPED);
});

test('compiles drawtext with expression expansion disabled', function (): void {
    $filter = (new FilterGraphCompiler)->compileDrawtext('/fonts/story.ttf', '%{pts}');

    expect($filter)->toBe(
        'drawtext=fontfile=/fonts/story.ttf:text=%{pts}:expansion=none:fontcolor=white:fontsize=48:x=20:y=60',
    );
});

test('rejects lossy line-break transformation', function (string $lineBreak): void {
    expect(fn (): string => (new FilterGraphCompiler)->escapeOptionValue("first{$lineBreak}second"))
        ->toThrow(InvalidArgumentException::class, 'cannot contain line breaks');
})->with([
    'line feed' => "\n",
    'carriage return' => "\r",
    'carriage return and line feed' => "\r\n",
]);
