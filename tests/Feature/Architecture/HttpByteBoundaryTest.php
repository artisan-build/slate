<?php

declare(strict_types=1);

/**
 * Finds source-level byte movement, admission, and driver access directly reachable in the scanned
 * request-layer files. This static reachability scan cannot see a violation hidden behind a container
 * binding resolved by string or behind implementation code outside the scanned tree.
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
        'byte-reading storage call' => '/->\s*(?:readStream|stageIn|download)\s*\(/',
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

test('HTTP code cannot directly move source bytes or invoke admission and the scan has a positive control', function (): void {
    $fixtureViolations = httpRequestByteBoundaryViolations(base_path('tests/Fixtures/HttpBoundary'));

    expect($fixtureViolations)->toHaveCount(3)
        ->and(httpRequestByteBoundaryViolations(app_path('Http')))->toBe([]);
});
