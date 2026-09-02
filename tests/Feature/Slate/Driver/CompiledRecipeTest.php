<?php

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutputType;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts compiler-owned scratch paths as local binary arguments', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp4');
        file_put_contents($input->writablePath(), 'video');
        $recipe = new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [
                CompiledArgument::option(CompiledOption::Input),
                CompiledArgument::existingLocalPath($input),
            ],
        );

        expect($recipe->command())->toBe(['/runtime/ffmpeg/bin/ffmpeg', '-i', $input->readablePath()]);
    });
});

test('rejects executable values not enum-selected from the supplied locator', function (string $executable): void {
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

test('rejects every caller-authored string as a local path', function (string $input): void {
    expect(fn (): CompiledArgument => CompiledArgument::localPath($input, CompiledOutputType::Video))
        ->toThrow(TypeError::class);
})->with([
    'absolute file' => '/etc/passwd',
    'root directory' => '/',
    'network-path reference' => '//evil.com/x',
    'FTP URL' => 'ftp://example.com/input.mp4',
    'RTMP URL' => 'rtmp://example.com/live',
    'TCP URL' => 'tcp://example.com:80',
    'SRT URL' => 'srt://example.com:9000',
    'HTTP control' => 'http://example.com/input.mp4',
]);

test('rejects positive durations outside compiler precision and range', function (float $seconds): void {
    expect(fn (): CompiledArgument => CompiledArgument::seconds($seconds))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'below microsecond precision' => 0.0000001,
    'half-microsecond boundary' => 0.0000005,
    'largest finite float' => PHP_FLOAT_MAX,
]);

test('accepts caller arithmetic within microsecond precision', function (): void {
    expect(CompiledArgument::seconds(0.1 + 0.2)->value())->toBe('0.3');
});

test('rejects URLs embedded in filtergraphs', function (string $filterGraph): void {
    expect(fn (): CompiledFilterGraph => CompiledFilterGraph::fromCompiler($filterGraph))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'movie input' => 'movie=http://example.com/input.mp4',
    'tee exfiltration' => 'tee:/tmp/output.mp4|http://example.com/output.mp4',
]);

test('rejects listed bare protocols embedded in filtergraphs', function (string $filterGraph): void {
    expect(fn (): CompiledFilterGraph => CompiledFilterGraph::fromCompiler($filterGraph))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'async' => 'movie=async:/tmp/input.mp4',
    'cache' => 'movie=cache:/tmp/input.mp4',
    'concat' => 'movie=concat:/tmp/one.mp4|/tmp/two.mp4',
    'crypto' => 'movie=crypto:/tmp/input.mp4',
    'data' => 'movie=data:text/plain,secret',
    'fd' => 'movie=fd:3',
    'file' => 'movie=file:/etc/passwd',
    'md5' => 'movie=md5:/tmp/input.mp4',
    'pipe' => 'movie=pipe:0',
    'subfile' => 'movie=subfile,,start,0,end,10,,:/etc/passwd',
    'unix' => 'movie=unix:/tmp/socket',
]);

test('accepts legitimate compiled filtergraphs near the bare protocol syntax', function (string $filterGraph): void {
    expect(CompiledFilterGraph::fromCompiler($filterGraph)->value())->toBe($filterGraph);
})->with([
    'concat filter' => 'concat=n=2:v=1:a=1',
    'escaped file text' => 'drawtext=text=file\\:',
    'scale' => 'scale=640:480',
    'overlay' => 'overlay=x=20:y=60',
    'format' => 'format=yuv420p',
    'fade' => 'fade=t=out:st=4:d=1',
    'xfade' => 'xfade=transition=fade:duration=1:offset=4',
    'local movie input' => 'movie=/tmp/input.mp4',
]);

test('rejects multiple simple video filters in one compiled recipe', function (): void {
    $first = CompiledFilterGraph::fromCompiler('scale=1536:1024');
    $second = CompiledFilterGraph::fromCompiler('format=yuv420p');

    expect(fn (): CompiledRecipe => new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffmpeg,
        [
            ...CompiledArgument::filter(CompiledFilterType::Video, $first),
            ...CompiledArgument::filter(CompiledFilterType::Video, $second),
        ],
    ))->toThrow(LogicException::class, 'more than one -vf');
});

test('rejects simple and complex video filters in one compiled recipe', function (): void {
    $simple = CompiledFilterGraph::fromCompiler('scale=1536:1024');
    $complex = CompiledFilterGraph::fromCompiler('[0:v][1:v]overlay[video]');

    expect(fn (): CompiledRecipe => new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffmpeg,
        [
            ...CompiledArgument::filter(CompiledFilterType::Video, $simple),
            ...CompiledArgument::filter(CompiledFilterType::Complex, $complex),
        ],
    ))->toThrow(LogicException::class, 'cannot combine -vf with -filter_complex');
});

test('rejects a filtergraph separated from its typed option', function (): void {
    $filter = CompiledArgument::filter(
        CompiledFilterType::Video,
        CompiledFilterGraph::fromCompiler('null'),
    );

    expect(fn (): CompiledRecipe => new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffmpeg,
        [$filter[1]],
    ))->toThrow(LogicException::class, 'cannot exist outside its typed option pair');
});

test('rejects every string option so unlisted ffmpeg aliases cannot enter argv', function (string $option): void {
    expect(method_exists(CompiledArgument::class, 'filterGraph'))->toBeFalse()
        ->and(fn (): CompiledArgument => CompiledArgument::option($option))
        ->toThrow(TypeError::class);
})->with([
    '-i',
    '-vf',
    '-filter:v',
    '-filter_complex',
    '-lavfi',
    '-lavfi:v',
    '-LAVFI',
    '-future-option',
]);

test('rejects implicit codec selection for every ffmpeg file output', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        expect(fn (): CompiledRecipe => new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [CompiledArgument::localPath($scratch->file('implicit.mp4'), CompiledOutputType::Video)],
        ))->toThrow(LogicException::class, 'select its media codec explicitly');
    });
});

test('does not accept an audio codec as explicit selection for a video output', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        expect(fn (): CompiledRecipe => new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [
                CompiledArgument::option(CompiledOption::AudioCodec),
                CompiledArgument::literal(CompiledLiteral::Copy),
                CompiledArgument::localPath($scratch->file('implicit-video.mp4'), CompiledOutputType::Video),
            ],
        ))->toThrow(LogicException::class, 'select its media codec explicitly');
    });
});
