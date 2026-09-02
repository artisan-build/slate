<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use RuntimeException;

final readonly class FfmpegLocator
{
    public function __construct(
        private string $osFamily,
        private string $machine,
        private ?string $runtimePathOverride = null,
    ) {}

    public static function fromSystem(): self
    {
        return new self(PHP_OS_FAMILY, php_uname('m'));
    }

    public function assetName(): string
    {
        return match ([$this->osFamily, $this->normalizedMachine()]) {
            ['Linux', 'arm64'] => (string) config('slate.ffmpeg.asset'),
            default => throw new RuntimeException("Unsupported ffmpeg platform [{$this->osFamily}/{$this->machine}]."),
        };
    }

    public function runtimePath(): string
    {
        return $this->runtimePathOverride ?? (string) config('slate.ffmpeg.runtime_path');
    }

    public function installationPath(): string
    {
        return $this->runtimePath().'/ffmpeg';
    }

    public function ffmpegPath(): string
    {
        return $this->installationPath().'/bin/ffmpeg';
    }

    public function ffprobePath(): string
    {
        return $this->installationPath().'/bin/ffprobe';
    }

    public function fontPath(): string
    {
        return (string) config('slate.ffmpeg.font_path');
    }

    public function isProvisioned(): bool
    {
        return is_file($this->ffmpegPath())
            && is_executable($this->ffmpegPath())
            && is_file($this->ffprobePath())
            && is_executable($this->ffprobePath());
    }

    private function normalizedMachine(): string
    {
        return match ($this->machine) {
            'aarch64' => 'arm64',
            'amd64', 'x64' => 'x86_64',
            default => $this->machine,
        };
    }
}
