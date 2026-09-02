<?php

use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledH264OutputOptions;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Recipe\QualityTier;

test('appends normalization to the operation filter chain', function (): void {
    $filter = (new FilterGraphCompiler)->compileH264Video([
        'crop=640:480:0:0',
        'fade=t=out:st=4:d=1',
    ]);

    expect($filter->filterGraph()->value())->toBe(
        'crop=640:480:0:0,fade=t=out:st=4:d=1,scale=in_range=auto:out_range=tv,format=yuv420p',
    );
});

test('attaches normalization to the labelled output of a complex video graph', function (): void {
    $filter = (new FilterGraphCompiler)->compileH264ComplexVideo(
        ['[1:v]fade=type=in:st=1:d=0.5:alpha=1[badge]'],
        '[0:v][badge]overlay=x=20:y=40',
    );

    expect($filter->complex)->toBeTrue()
        ->and($filter->filterGraph()->value())->toBe(
            '[1:v]fade=type=in:st=1:d=0.5:alpha=1[badge];'
            .'[0:v][badge]overlay=x=20:y=40[operation_video];'
            .'[operation_video]scale=in_range=auto:out_range=tv,format=yuv420p[video]',
        );
});

test('reserves normalized output labels from operation-authored complex chains', function (array $prefix, string $terminal): void {
    expect(fn () => (new FilterGraphCompiler)->compileH264ComplexVideo($prefix, $terminal))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'video label in prefix' => [['[0:v]null[video]'], '[1:v]null'],
    'operation label in prefix' => [['[0:v]null[operation_video]'], '[1:v]null'],
    'video label in terminal' => [[], '[0:v]null[video]'],
    'custom terminal output label' => [[], '[0:v]null[custom]'],
]);

test('requires a normalized graph before H264 output arguments can be constructed', function (): void {
    $unnormalized = CompiledFilterGraph::fromCompiler('scale=1536:1024');

    expect(fn (): CompiledOutput => CompiledOutput::h264(
        $unnormalized,
        QualityTier::Delivery,
        1536,
        1024,
        24,
        CompiledH264OutputOptions::concat(),
        new stdClass,
    ))->toThrow(TypeError::class);
});

test('escapes drawtext option values for both ffmpeg parser levels', function (): void {
    $escaped = (new FilterGraphCompiler)->escapeOptionValue("it's: 50%\\ready");

    expect($escaped)->toBe(<<<'ESCAPED'
it\\\'s\\: 50%\\\\ready
ESCAPED);
});

test('compiles drawtext with expression expansion disabled', function (): void {
    $filter = (new FilterGraphCompiler)->compileDrawtext('/fonts/story.ttf', '%{pts}');

    expect($filter->value())->toBe(
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
