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
 * Finds binary authority above the driver boundary without depending on process-call shape.
 *
 * PHP has no namespace-private visibility, so this token scan enforces placement: it finds any
 * FfmpegLocator/path-accessor or CompiledRecipe reference and any string literal containing `ffmpeg`
 * or `ffprobe`. It ignores comments, but cannot detect names assembled from split strings at runtime.
 * Existing provisioning, diagnostics, and PR 7 benchmark residue are held to an exact location map.
 *
 * @return array<string, list<string>>
 */
function binaryAuthorityLocations(string $root): array
{
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

        if (str_starts_with($relativePath, 'Slate/Driver/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$tokenType, $text] = $token;

            if (in_array($tokenType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if (in_array($text, ['FfmpegLocator', 'ffmpegPath', 'ffprobePath'], true)
                    || str_ends_with($text, '\\FfmpegLocator')) {
                    $locations['locator or path accessor'][] = $relativePath;
                }

                if ($text === 'CompiledRecipe' || str_ends_with($text, '\\CompiledRecipe')) {
                    $locations['compiled recipe reference'][] = $relativePath;
                }
            }

            if (in_array($tokenType, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/ffmpeg|ffprobe/i', $text) === 1) {
                $locations['binary string literal'][] = $relativePath;
            }
        }
    }

    ksort($locations);

    foreach ($locations as $kind => $paths) {
        $paths = array_values(array_unique($paths));
        sort($paths);
        $locations[$kind] = $paths;
    }

    return $locations;
}

/**
 * Finds known v1 ffmpeg filter fragments above the driver boundary.
 *
 * This source-text scan recognizes assignments for Slate's documented v1 filter vocabulary, including
 * the `movie=` and `concat=` protocol-bearing disclosure vectors. An unknown filter name or a fragment
 * assembled from split strings is not detectable here. The real containment is that Driver::run accepts
 * only a CompiledRecipe and the binary-authority scan confines recipe construction to the compiler.
 *
 * @param  list<string>  $exceptions
 * @return list<string>
 */
function ffmpegFilterFragmentLocations(string $root, array $exceptions = []): array
{
    return sourcePatternLocations(
        $root,
        '/(?<=[\'",])(?:acrossfade|adelay|anullsrc|apad|concat|drawtext|fade|format|metadata|movie|overlay|scale|xfade|zoompan)\s*=/',
        $exceptions,
        ['Slate/Driver/'],
    );
}

/**
 * Finds drawtext `text=` emitters inside the compiler boundary.
 *
 * The caller-layer filter scan deliberately excludes Slate/Driver, so this exact-location scan ensures
 * a second compiler emitter cannot omit the escaping and `expansion=none` contract unnoticed. It is a
 * source-text scan and cannot detect an emitter assembled from split strings at runtime.
 *
 * @return list<string>
 */
function drawtextEmitterLocations(string $root): array
{
    return sourcePatternLocations($root, '/[\'"].*?text\s*=/');
}

/**
 * Finds direct S3 client, disk, or object-URL construction above Slate's storage boundary.
 *
 * This source-text scan recognizes fully qualified and imported AWS S3 clients, direct or configured
 * S3ObjectStorage construction, and Laravel Storage facade disk/build/drive/cloud/URL calls. It cannot
 * detect aliased classes or dynamic method names; the storage interface and code review cover that residue.
 *
 * @return list<string>
 */
function storageBoundaryLocations(string $root): array
{
    return sourcePatternLocations(
        $root,
        '/(?:new\s+(?:\\\\?Aws\\\\S3\\\\S3Client|S3Client|S3ObjectStorage)\s*\(|S3ObjectStorage::fromConfiguredDisk\s*\(|Storage::(?:build|cloud|disk|drive|temporaryUrl|url)\s*\()/',
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
