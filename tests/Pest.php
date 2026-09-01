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
 * encoder-name placement only. It does not enforce the argv or filter-fragment clauses of Slate's
 * boundary invariant; the existing FfmpegBenchmark residue remains for PR 3.
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

    return $locations;
}
