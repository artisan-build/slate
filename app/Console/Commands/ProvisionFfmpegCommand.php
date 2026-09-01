<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FfmpegLocator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('slate:provision-ffmpeg {--force}')]
#[Description('Download, verify, extract, and trim the pinned ffmpeg build.')]
final class ProvisionFfmpegCommand extends Command
{
    public function handle(): int
    {
        $locator = FfmpegLocator::fromSystem();

        if (! (bool) $this->option('force') && $locator->isProvisioned()) {
            $this->components->info('ffmpeg is already present; use --force to reinstall.');
            $this->line('ffmpeg: '.$locator->ffmpegPath());
            $this->line('ffprobe: '.$locator->ffprobePath());

            return self::SUCCESS;
        }

        try {
            $this->assertXzAvailable();
            $this->provision($locator);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('ffmpeg provisioning complete.');
        $this->line('ffmpeg: '.$locator->ffmpegPath());
        $this->line('ffprobe: '.$locator->ffprobePath());

        return self::SUCCESS;
    }

    private function assertXzAvailable(): void
    {
        $result = Process::timeout(10)->run(['xz', '--version']);

        if ($result->failed()) {
            throw new RuntimeException(
                'Missing required `xz` executable. Install the `xz-utils` package in the build image before running `slate:provision-ffmpeg`.',
            );
        }
    }

    private function provision(FfmpegLocator $locator): void
    {
        $runtimePath = $locator->runtimePath();
        $asset = $locator->assetName();
        $tag = (string) config('slate.ffmpeg.release_tag');
        $baseUrl = "https://github.com/BtbN/FFmpeg-Builds/releases/download/{$tag}";
        $archivePath = $runtimePath.'/'.$asset.'.download';
        $extractPath = $runtimePath.'/ffmpeg-extract';

        $this->ensureDirectory($runtimePath);
        $this->removeDirectory($extractPath);

        try {
            $checksums = $this->download("{$baseUrl}/checksums.sha256");
            $expectedHash = $this->checksumForAsset($checksums, $asset);

            Http::retry([250, 500, 1000])
                ->timeout(300)
                ->connectTimeout(15)
                ->sink($archivePath)
                ->get("{$baseUrl}/{$asset}")
                ->throw();

            $actualHash = hash_file('sha256', $archivePath);

            if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException("Checksum mismatch for {$asset}.");
            }

            $this->extractAndTrim($archivePath, $extractPath);
            $this->replaceInstallation($extractPath, $locator->installationPath());
        } finally {
            @unlink($archivePath);
            $this->removeDirectory($extractPath);
        }
    }

    private function extractAndTrim(string $archivePath, string $extractPath): void
    {
        $this->ensureDirectory($extractPath);

        $result = Process::timeout(300)->run([
            'tar',
            '-xf',
            $archivePath,
            '-C',
            $extractPath,
            '--strip-components=1',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Unable to extract ffmpeg: '.trim($result->errorOutput()));
        }

        $fullSize = $this->directorySize($extractPath);

        foreach (['bin/ffmpeg', 'bin/ffprobe'] as $binary) {
            $binaryPath = $extractPath.'/'.$binary;

            if (! is_file($binaryPath)) {
                throw new RuntimeException("Extracted archive is missing {$binary}.");
            }

            if (! chmod($binaryPath, 0755)) {
                throw new RuntimeException("Unable to make {$binary} executable.");
            }
        }

        @unlink($extractPath.'/bin/ffplay');

        foreach (['doc', 'man', 'presets'] as $directory) {
            $this->removeDirectory($extractPath.'/'.$directory);
        }

        $this->line(sprintf(
            'Footprint: %.1f MB extracted; %.1f MB after trim.',
            $fullSize / 1024 / 1024,
            $this->directorySize($extractPath) / 1024 / 1024,
        ));
    }

    private function replaceInstallation(string $extractPath, string $installationPath): void
    {
        $this->removeDirectory($installationPath);

        if (! rename($extractPath, $installationPath)) {
            throw new RuntimeException("Unable to install ffmpeg at {$installationPath}.");
        }
    }

    private function download(string $url): string
    {
        return Http::retry([250, 500, 1000])
            ->timeout(120)
            ->connectTimeout(15)
            ->get($url)
            ->throw()
            ->body();
    }

    private function checksumForAsset(string $checksums, string $asset): string
    {
        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_ends_with($line, $asset)) {
                continue;
            }

            $hash = Str::of($line)->before(' ')->trim()->toString();

            if (preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) {
                return strtolower($hash);
            }
        }

        throw new RuntimeException("Unable to find an upstream checksum for {$asset}.");
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create directory {$path}.");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }

    private function directorySize(string $path): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
