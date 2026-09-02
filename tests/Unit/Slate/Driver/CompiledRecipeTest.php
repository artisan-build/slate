<?php

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;

test('accepts compiler-owned local binary arguments', function (): void {
    $recipe = new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffmpeg,
        [
            CompiledArgument::option('-i'),
            CompiledArgument::localPath('/tmp/input.mp4'),
        ],
    );

    expect($recipe->command())->toBe(['/runtime/ffmpeg/bin/ffmpeg', '-i', '/tmp/input.mp4']);
});

test('rejects executable paths not selected by the locator', function (string $executable): void {
    expect(fn (): CompiledRecipe => new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        $executable,
        [],
    ))->toThrow(TypeError::class);
})->with([
    'PATH lookup' => 'ffmpeg',
    'relative executable' => './ffmpeg',
    'different absolute executable' => '/tmp/evil/ffmpeg',
]);

test('rejects protocol inputs as local paths', function (string $input): void {
    expect(fn (): CompiledArgument => CompiledArgument::localPath($input))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'FTP URL' => 'ftp://example.com/input.mp4',
    'RTMP URL' => 'rtmp://example.com/live',
    'TCP URL' => 'tcp://example.com:80',
    'SRT URL' => 'srt://example.com:9000',
    'HTTP control' => 'http://example.com/input.mp4',
]);

test('rejects URLs embedded in filtergraphs', function (string $filterGraph): void {
    expect(fn (): CompiledFilterGraph => CompiledFilterGraph::fromCompiler($filterGraph))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'movie input' => 'movie=http://example.com/input.mp4',
    'tee exfiltration' => 'tee:/tmp/output.mp4|http://example.com/output.mp4',
]);
