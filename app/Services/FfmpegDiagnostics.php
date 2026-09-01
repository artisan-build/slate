<?php

declare(strict_types=1);

namespace App\Services;

final class FfmpegDiagnostics
{
    /**
     * @var list<string>
     */
    public const TARGET_ENCODERS = [
        'libopenh264',
        'libkvazaar',
        'libsvtav1',
        'libvpx-vp9',
    ];

    public function hasDrawtextFilter(string $filters): bool
    {
        return preg_match('/^\s*[A-Z.]{2,3}\s+drawtext\s/m', $filters) === 1;
    }

    public function hasRequiredFontBuildFlags(string $buildConfiguration): bool
    {
        foreach (['libfreetype', 'fontconfig', 'libharfbuzz', 'libfribidi'] as $library) {
            if (! str_contains($buildConfiguration, "--enable-{$library}")) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function registeredTargetEncoders(string $encoders): array
    {
        return array_values(array_filter(
            self::TARGET_ENCODERS,
            static fn (string $encoder): bool => preg_match(
                '/^\s*[A-Z.]{6}\s+'.preg_quote($encoder, '/').'\s/m',
                $encoders,
            ) === 1,
        ));
    }

    public function averageLuma(string $output): ?float
    {
        if (preg_match('/lavfi\.signalstats\.YAVG=([0-9]+(?:\.[0-9]+)?)/', $output, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    public function pixelsChanged(?float $blankLuma, ?float $drawtextLuma): bool
    {
        return $blankLuma !== null
            && $drawtextLuma !== null
            && $drawtextLuma > $blankLuma + 0.5;
    }
}
