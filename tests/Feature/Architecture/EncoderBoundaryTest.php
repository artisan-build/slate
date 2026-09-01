<?php

/**
 * Finds literal encoder names in PHP source text. This cannot detect a name assembled at runtime from fragments.
 *
 * @return list<string>
 */
function filesContainingEncoderNames(string $root): array
{
    $paths = [];

    if (is_file($root)) {
        $paths[] = $root;
    } else {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }

    $matches = [];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$path}");
        }

        if (preg_match('/\\b(?:libopenh264|libkvazaar|libsvtav1|libvpx-vp9|libx264|libx265)\\b/', $contents) !== 1) {
            continue;
        }

        $matches[] = is_file($root)
            ? basename($path)
            : str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
    }

    sort($matches);

    return $matches;
}

test('allows encoder names only at explicit binary boundaries', function (): void {
    $matches = filesContainingEncoderNames(base_path('app'));

    expect($matches)->toBe([
        'Services/FfmpegDiagnostics.php',
        'Slate/Driver/Binary/H264RateControl.php',
    ]);
});

test('source scan catches a rogue encoder name', function (): void {
    $fixture = base_path('tests/Fixtures/rogue-encoder.php');

    expect(filesContainingEncoderNames($fixture))->toBe(['rogue-encoder.php']);
});
