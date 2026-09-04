<?php

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledFilterType;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

test('accepts compiler-owned scratch paths as local binary arguments', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp4');
        file_put_contents($input->writablePath(), 'video');
        $recipe = new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [CompiledInput::ffmpeg($input)],
        );

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            ...ffmpegFileInputHardeningArguments(),
            '-i',
            $input->readablePath(),
            ...ffmpegOutputHardeningArguments(),
        ]);
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

test('exposes no standalone output path or codec option construction', function (): void {
    expect(method_exists(CompiledArgument::class, 'localPath'))->toBeFalse()
        ->and(method_exists(CompiledArgument::class, 'existingLocalPath'))->toBeFalse()
        ->and(method_exists(CompiledArgument::class, 'input'))->toBeFalse()
        ->and(CompiledOption::tryFrom('-c'))->toBeNull()
        ->and(CompiledOption::tryFrom('-c:a'))->toBeNull()
        ->and(CompiledOption::tryFrom('-c:v'))->toBeNull();
});

test('rejects a detached local input source in terminal output position', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $input = $scratch->file('input.mp4');
        $victim = $scratch->file('victim.mp4');
        file_put_contents($input->writablePath(), 'input');
        file_put_contents($victim->writablePath(), 'victim');
        $inputUnit = CompiledInput::ffmpeg($input);
        $victimUnit = CompiledInput::ffprobe($victim);

        expect(fn (): CompiledRecipe => new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [$inputUnit, $victimUnit],
        ))->toThrow(LogicException::class, 'different executable');
    });
});

test('rejects a free literal in positional output scope', function (): void {
    expect(fn (): CompiledRecipe => new CompiledRecipe(
        new FfmpegLocator('Linux', 'arm64', '/runtime'),
        BinaryExecutable::Ffmpeg,
        [CompiledArgument::literal(CompiledLiteral::Mp3)],
    ))->toThrow(LogicException::class, 'value cannot exist outside its typed option pair');
});

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

test('keeps codec selection inside the same ordered output unit as its path', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $output = $scratch->file('poster.jpg');

        $recipe = new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [CompiledOutput::jpeg($output)],
        );

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            ...ffmpegFileInputHardeningArguments(),
            ...ffmpegOutputHardeningArguments(),
            '-c:v',
            'mjpeg',
            '-f',
            'image2',
            $output->writablePath(),
        ]);
    });
});

test('supports multiple independently scoped outputs with different media codecs', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $audio = $scratch->file('audio.mp3');
        $image = $scratch->file('poster.jpg');

        $recipe = new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [
                CompiledOutput::copiedMp3($audio),
                CompiledOutput::jpeg($image),
            ],
        );

        expect($recipe->command())->toBe([
            '/runtime/ffmpeg/bin/ffmpeg',
            ...ffmpegInvocationHardeningArguments(),
            ...ffmpegFileInputHardeningArguments(),
            ...ffmpegOutputHardeningArguments(),
            '-c:a', 'copy', '-f', 'mp3', $audio->writablePath(),
            ...ffmpegOutputHardeningArguments(),
            '-c:v', 'mjpeg', '-f', 'image2', $image->writablePath(),
        ]);
    });
});

test('rejects source-derived video copy into a consumer deliverable but permits a typed intermediate', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $source = $scratch->file('source.mp4');
        file_put_contents($source->writablePath(), 'source video');
        $sourceInput = CompiledInput::sourceVideo($source);
        $intermediate = CompiledOutput::copiedIntermediateVideo(
            $sourceInput,
            $scratch->file('coarse.mp4'),
            10.0,
        );

        $recipe = new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [$sourceInput, $intermediate],
        );

        expect($recipe->command())->toContain('-c', 'copy')
            ->and(fn (): CompiledOutput => CompiledOutput::copiedVideo(
                $sourceInput,
                $scratch->file('deliverable.mp4'),
                10.0,
            ))->toThrow(LogicException::class, 'cannot stream-copy source-derived video bytes');
    });
});

test('rejects a copied output bound to a different compiled input object', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $video = $scratch->file('slate.mp4');
        file_put_contents($video->writablePath(), 'slate video');
        $boundInput = CompiledInput::slateVideo($video);
        $differentInput = CompiledInput::slateVideo($video);
        $output = CompiledOutput::copiedVideo($boundInput, $scratch->file('deliverable.mp4'));

        expect(fn (): CompiledRecipe => new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [$differentInput, $output],
        ))->toThrow(LogicException::class, 'bind to its exact compiled input');
    });
});

test('rejects diagnostic early-exit options in a file-output recipe', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        expect(fn (): CompiledRecipe => new CompiledRecipe(
            new FfmpegLocator('Linux', 'arm64', '/runtime'),
            BinaryExecutable::Ffmpeg,
            [
                CompiledArgument::option(CompiledOption::Filters),
                CompiledOutput::jpeg($scratch->file('poster.jpg')),
            ],
        ))->toThrow(LogicException::class, 'Diagnostic early-exit');
    });
});

test('rejects extra output injection through closed H264 and MP3 option types', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $extraOutput = CompiledArgument::literal(CompiledLiteral::Mp3);
        $filter = (new FilterGraphCompiler)->compileH264Video(['null']);

        expect(fn (): CompiledOutput => CompiledOutput::h264(
            $filter,
            QualityTier::Delivery,
            1536,
            1024,
            24,
            [$extraOutput],
            $scratch->file('intended.mp4'),
        ))->toThrow(TypeError::class)
            ->and(fn (): CompiledOutput => CompiledOutput::mp3(
                AudioBitrate::Kbps192,
                $scratch->file('intended.mp3'),
                [$extraOutput],
            ))->toThrow(TypeError::class);
    });
});
