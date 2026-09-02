<?php

use App\Services\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Renders through the pinned binary and compares raw-frame hashes. The textfile reference avoids
 * inline text escaping entirely, while the plain-text control proves the apostrophe changes pixels.
 */
test('renders an apostrophe identically to a known-good textfile reference', function (): void {
    $locator = FfmpegLocator::fromSystem();

    if (! $locator->isProvisioned()) {
        $message = 'Pinned ffmpeg runtime unavailable; rendered drawtext escaping lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    $directory = storage_path('framework/testing/filter-escaping-'.uniqid());
    mkdir($directory, 0755, true);
    $fontPath = $directory.'/font.ttf';
    $textPath = $directory.'/reference.txt';
    $generatedFilterPath = $directory.'/generated.filter';
    $referenceFilterPath = $directory.'/reference.filter';
    $plainFilterPath = $directory.'/plain.filter';
    $options = ':expansion=none:fontcolor=white:fontsize=48:x=20:y=60';

    copy($locator->fontPath(), $fontPath);
    file_put_contents($textPath, "it's");
    file_put_contents(
        $generatedFilterPath,
        (new FilterGraphCompiler)->compileDrawtext($fontPath, "it's"),
    );
    file_put_contents(
        $referenceFilterPath,
        "drawtext=fontfile={$fontPath}:textfile={$textPath}{$options}",
    );
    file_put_contents(
        $plainFilterPath,
        "drawtext=fontfile={$fontPath}:text=its{$options}",
    );

    $render = static function (string $filterPath) use ($locator): string {
        $result = Process::timeout(30)->run([
            $locator->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=640x200:d=1:r=1',
            '-/filter:v',
            $filterPath,
            '-frames:v',
            '1',
            '-f',
            'md5',
            '-',
        ]);

        expect($result->successful())->toBeTrue($result->errorOutput());

        return trim($result->output());
    };

    try {
        $generatedHash = $render($generatedFilterPath);
        $referenceHash = $render($referenceFilterPath);
        $plainHash = $render($plainFilterPath);

        expect($referenceHash)->not->toBe($plainHash)
            ->and($generatedHash)->toBe($referenceHash);
    } finally {
        File::deleteDirectory($directory);
    }
})->group('ffmpeg-render');
