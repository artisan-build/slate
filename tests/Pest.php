<?php

use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

function ffmpegFixture(string $name): string
{
    $contents = file_get_contents(__DIR__.'/Fixtures/'.$name);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to load fixture {$name}.");
    }

    return $contents;
}

/**
 * Finds documented encoder literals in PHP files beneath a directory.
 *
 * This is a source-text scan: it cannot detect names assembled from fragments at runtime. It enforces
 * only the documented encoder names in its regex; capability diagnostics remain an approved literal
 * location because they must inspect the encoder table. Separate scans enforce detectable argv and
 * filter-fragment construction.
 *
 * @return array<string, list<string>>
 */
function encoderNameLocations(string $root): array
{
    $encoderPattern = '/\\b(?:aac|libaom-av1|libkvazaar|libmp3lame|libopenh264|libopus|libsvtav1|libvorbis|libvpx-vp9|libx264|libx265|pcm_s16le|prores_ks|h264_(?:amf|nvenc|v4l2m2m|vulkan))\\b/';
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        if (preg_match_all($encoderPattern, $contents, $matches) < 1) {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        foreach (array_unique($matches[0]) as $encoder) {
            $locations[$encoder][] = $relativePath;
        }
    }

    ksort($locations);

    foreach ($locations as $encoder => $paths) {
        sort($paths);
        $locations[$encoder] = $paths;
    }

    return $locations;
}

/**
 * The benchmark harness predates the sealed vocabulary and PR 7 supersedes it.
 *
 * These exact files may retain hand-built ffmpeg commands, a drawtext fragment, and queued local paths.
 * No directory or wildcard is exempted, so a copied service or job is still scanned.
 *
 * @return list<string>
 */
function legacyFfmpegBoundaryExceptions(): array
{
    return [
        'Jobs/FinalizeFfmpegBenchmark.php',
        'Jobs/PrepareFfmpegBenchmark.php',
        'Jobs/ProcessFfmpegBenchmarkChunk.php',
        'Services/FfmpegBenchmark.php',
    ];
}

/**
 * Finds detectable ffmpeg command construction above the driver boundary.
 *
 * This source-text scan catches array commands whose first expression resolves an ffmpeg/ffprobe path,
 * plus direct CompiledRecipe construction. It cannot detect aliases, runtime method names, or commands
 * assembled indirectly; review and the typed public API cover that residue.
 *
 * @param  list<string>  $exceptions
 * @return list<string>
 */
function ffmpegArgumentLocations(string $root, array $exceptions = []): array
{
    return sourcePatternLocations(
        $root,
        '/\b(?:run|start)\s*\(\s*\[\s*[^\r\n]*(?:ffmpegPath|ffprobePath)\s*\(|new\s+CompiledRecipe\s*\(/',
        $exceptions,
        ['Slate/Driver/'],
    );
}

/**
 * Finds known v1 ffmpeg filter fragments above the driver boundary.
 *
 * This source-text scan recognizes assignments for Slate's documented v1 filter vocabulary. It cannot
 * detect unknown filter names or fragments assembled from split strings at runtime; typed operations,
 * compiler review, and the argv scan remain necessary alongside it.
 *
 * @param  list<string>  $exceptions
 * @return list<string>
 */
function ffmpegFilterFragmentLocations(string $root, array $exceptions = []): array
{
    return sourcePatternLocations(
        $root,
        '/(?<=[\'",])(?:acrossfade|adelay|anullsrc|apad|drawtext|fade|format|metadata|overlay|scale|xfade|zoompan)\s*=/',
        $exceptions,
        ['Slate/Driver/'],
    );
}

/**
 * Finds direct S3 client, disk, or object-URL construction above Slate's storage boundary.
 *
 * This source-text scan recognizes the AWS S3 client, S3ObjectStorage construction, and Laravel Storage
 * facade disk/build/URL calls. It cannot detect aliased classes or dynamic method names; the storage
 * interface and code review cover that residue.
 *
 * @return list<string>
 */
function storageBoundaryLocations(string $root): array
{
    return sourcePatternLocations(
        $root,
        '/(?:new\s+(?:\\?Aws\\S3\\S3Client|S3Client|S3ObjectStorage)\s*\(|Storage::(?:build|disk|temporaryUrl|url)\s*\()/',
        excludedPrefixes: ['Slate/Storage/'],
    );
}

/**
 * @param  list<string>  $exceptions
 * @param  list<string>  $excludedPrefixes
 * @return list<string>
 */
function sourcePatternLocations(
    string $root,
    string $pattern,
    array $exceptions = [],
    array $excludedPrefixes = [],
): array {
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        if (in_array($relativePath, $exceptions, true)
            || array_any($excludedPrefixes, fn (string $prefix): bool => str_starts_with($relativePath, $prefix))) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        if (preg_match($pattern, $contents) === 1) {
            $locations[] = $relativePath;
        }
    }

    sort($locations);

    return $locations;
}
