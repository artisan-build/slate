<?php

declare(strict_types=1);

/**
 * Finds an enumerated set of source-level byte movement, admission, and driver mechanisms directly in
 * the scanned request-layer files. The enumeration is not exhaustive. This static scan does not follow
 * first-party call targets, so a controller that invokes a violating service one hop away is invisible;
 * it also cannot see a violation hidden behind a container binding resolved by string.
 *
 * @return list<string>
 */
function httpRequestByteBoundaryViolations(string $root): array
{
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));
    $patterns = [
        'input admission' => '/\bInputAdmission\b/',
        'driver resolution' => '/(?:resolve|app|make)\s*\([^)]*\bDriver::class\b|\bDriver\s+\$/',
        'streamed response' => '/\bStreamedResponse\b/',
        'read stream' => '/->\s*readStream\s*\(/',
        'stage in' => '/->\s*stageIn\s*\(/',
        'download' => '/->\s*download\s*\(/',
        'storage facade get' => '/\bStorage::disk\s*\([^)]*\)\s*->\s*get\s*\(/',
        'storage object get' => '/\$(?:storage|filesystem|disk)\w*\s*->\s*get\s*\(/i',
        'file get contents' => '/\bfile_get_contents\s*\(/',
        'upload part' => '/->\s*uploadPart\s*\(/',
    ];

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to scan request-layer file: '.$file->getPathname());
        }

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = $label.':'.$file->getPathname();
            }
        }
    }

    return $violations;
}

test('enumerated HTTP byte movement and admission mechanisms are absent and each has a positive control', function (): void {
    $fixtureViolations = httpRequestByteBoundaryViolations(base_path('tests/Fixtures/HttpBoundary'));
    $labels = array_map(
        static fn (string $violation): string => strstr($violation, ':', true),
        $fixtureViolations,
    );
    sort($labels);
    $expectedLabels = [
        'download',
        'driver resolution',
        'file get contents',
        'input admission',
        'read stream',
        'stage in',
        'storage facade get',
        'storage object get',
        'streamed response',
        'upload part',
    ];
    sort($expectedLabels);

    expect($labels)->toBe($expectedLabels)
        ->and(httpRequestByteBoundaryViolations(app_path('Http')))->toBe([]);
});
