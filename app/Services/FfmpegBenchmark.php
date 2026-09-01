<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class FfmpegBenchmark
{
    /**
     * @return array{
     *     chunks: list<string>,
     *     source_size_bytes: int,
     *     generation_duration_ms: int,
     *     split_duration_ms: int,
     *     started_at_ms: int,
     *     worker: array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}
     * }
     */
    public function prepare(string $runId, int $durationSeconds, int $chunkSeconds): array
    {
        $scratchPath = $this->scratchPath($runId);
        $sourcePath = $scratchPath.'/source.mp4';
        $startedAtMs = $this->milliseconds();

        $this->ensureDirectory($scratchPath);
        $this->assertRuntimeAvailable();
        $this->assertEncoderAvailable('libopenh264');

        $generationStartedAt = $this->milliseconds();
        $this->generateSyntheticSource($sourcePath, $durationSeconds, $chunkSeconds);
        $generationDurationMs = $this->milliseconds() - $generationStartedAt;

        $splitStartedAt = $this->milliseconds();
        $this->segmentSource($sourcePath, $scratchPath, $chunkSeconds);
        $splitDurationMs = $this->milliseconds() - $splitStartedAt;

        $chunks = glob($scratchPath.'/chunk-*.mp4') ?: [];
        sort($chunks, SORT_NATURAL);

        if ($chunks === []) {
            throw new RuntimeException('The segment muxer did not produce any chunks.');
        }

        return [
            'chunks' => $chunks,
            'source_size_bytes' => $this->fileSize($sourcePath),
            'generation_duration_ms' => $generationDurationMs,
            'split_duration_ms' => $splitDurationMs,
            'started_at_ms' => $startedAtMs,
            'worker' => $this->workerLimits(),
        ];
    }

    public function generateSyntheticSource(string $outputPath, int $durationSeconds, int $chunkSeconds): void
    {
        $frameRate = 30;
        $keyframeInterval = $chunkSeconds * $frameRate;

        $this->run([
            $this->locator()->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'lavfi',
            '-i',
            "testsrc2=size=1280x720:rate={$frameRate}:duration={$durationSeconds}",
            '-an',
            '-c:v',
            'libopenh264',
            '-b:v',
            '2M',
            '-g',
            (string) $keyframeInterval,
            '-force_key_frames',
            "expr:gte(t,n_forced*{$chunkSeconds})",
            '-pix_fmt',
            'yuv420p',
            '-y',
            $outputPath,
        ], 'generate synthetic benchmark source');
    }

    private function segmentSource(string $sourcePath, string $scratchPath, int $chunkSeconds): void
    {
        $this->run([
            $this->locator()->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
            '-c',
            'copy',
            '-f',
            'segment',
            '-segment_time',
            (string) $chunkSeconds,
            '-reset_timestamps',
            '1',
            '-y',
            $scratchPath.'/chunk-%03d.mp4',
        ], 'split source with the segment muxer');
    }

    /**
     * @return array{chunk_index: int, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int, worker: array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}}
     */
    public function processChunk(string $runId, int $chunkIndex, string $inputPath): array
    {
        if (! is_file($inputPath)) {
            throw new RuntimeException("Chunk input is unavailable on this worker: {$inputPath}");
        }

        $outputPath = $this->scratchPath($runId).sprintf('/encoded-%03d.mp4', $chunkIndex);
        $mediaDurationMs = $this->mediaDurationMs($inputPath);
        $startedAt = $this->milliseconds();
        $fontPath = $this->locator()->fontPath();

        $this->run([
            $this->locator()->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $inputPath,
            '-vf',
            $this->drawtextFilter($fontPath, $chunkIndex),
            '-an',
            '-c:v',
            'libopenh264',
            '-b:v',
            '2M',
            '-pix_fmt',
            'yuv420p',
            '-y',
            $outputPath,
        ], "encode chunk {$chunkIndex}");

        $measurement = [
            'chunk_index' => $chunkIndex,
            'media_duration_ms' => $mediaDurationMs,
            'processing_duration_ms' => $this->milliseconds() - $startedAt,
            'output_size_bytes' => $this->fileSize($outputPath),
            'worker' => $this->workerLimits(),
        ];

        $this->writeJson(
            $this->scratchPath($runId).sprintf('/measurement-%03d.json', $chunkIndex),
            $measurement,
        );

        return $measurement;
    }

    /**
     * @return array{concat_duration_ms: int, output_size_bytes: int}
     */
    public function concatenate(string $runId, int $chunkCount): array
    {
        $scratchPath = $this->scratchPath($runId);
        $concatPath = $scratchPath.'/concat.txt';
        $outputPath = $scratchPath.'/benchmark.mp4';
        $lines = [];

        for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
            $chunkName = sprintf('encoded-%03d.mp4', $chunkIndex);

            if (! is_file($scratchPath.'/'.$chunkName)) {
                throw new RuntimeException("Encoded chunk is unavailable on this worker: {$chunkName}");
            }

            $lines[] = "file '{$chunkName}'";
        }

        file_put_contents($concatPath, implode(PHP_EOL, $lines).PHP_EOL);
        $startedAt = $this->milliseconds();

        $this->run([
            $this->locator()->ffmpegPath(),
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            basename($concatPath),
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            '-y',
            basename($outputPath),
        ], 'reassemble encoded chunks with the concat demuxer', $scratchPath);

        return [
            'concat_duration_ms' => $this->milliseconds() - $startedAt,
            'output_size_bytes' => $this->fileSize($outputPath),
        ];
    }

    /**
     * @return list<array{chunk_index: int, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int, worker: array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}}>
     */
    public function measurements(string $runId, int $chunkCount): array
    {
        $measurements = [];

        for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
            $path = $this->scratchPath($runId).sprintf('/measurement-%03d.json', $chunkIndex);
            $contents = is_file($path) ? file_get_contents($path) : false;

            if (! is_string($contents)) {
                throw new RuntimeException("Chunk measurement is unavailable on this worker: {$path}");
            }

            try {
                $measurement = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("Invalid chunk measurement: {$path}", previous: $exception);
            }

            if (! is_array($measurement)) {
                throw new RuntimeException("Invalid chunk measurement: {$path}");
            }

            /** @var array{chunk_index: int, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int, worker: array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}} $measurement */
            $measurements[] = $measurement;
        }

        return $measurements;
    }

    /**
     * @param  list<array{chunk_index: int, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int, worker: array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}}>  $measurements
     * @return array{media_duration_ms: int, chunk_processing_ms: int, total_wall_ms: int, encoding_realtime_factor: float, end_to_end_realtime_factor: float, peak_chunk_ms: int, visibility_timeout_ms: int, visibility_headroom_ms: int}
     */
    public function summarizeMeasurements(
        array $measurements,
        int $startedAtMs,
        int $finishedAtMs,
        int $visibilityTimeoutSeconds,
    ): array {
        $mediaDurationMs = array_sum(array_column($measurements, 'media_duration_ms'));
        $processingDurationMs = array_sum(array_column($measurements, 'processing_duration_ms'));
        $peakChunkMs = max(array_column($measurements, 'processing_duration_ms'));
        $totalWallMs = max(1, $finishedAtMs - $startedAtMs);
        $visibilityTimeoutMs = $visibilityTimeoutSeconds * 1000;

        return [
            'media_duration_ms' => $mediaDurationMs,
            'chunk_processing_ms' => $processingDurationMs,
            'total_wall_ms' => $totalWallMs,
            'encoding_realtime_factor' => round($mediaDurationMs / max(1, $processingDurationMs), 3),
            'end_to_end_realtime_factor' => round($mediaDurationMs / $totalWallMs, 3),
            'peak_chunk_ms' => $peakChunkMs,
            'visibility_timeout_ms' => $visibilityTimeoutMs,
            'visibility_headroom_ms' => $visibilityTimeoutMs - $peakChunkMs,
        ];
    }

    /**
     * @return array{hostname: string, memory_max: string, memory_bytes: int|null, cpu_max: string, cpu_cores: float|null}
     */
    public function workerLimits(): array
    {
        $memoryMax = $this->readCgroup('/sys/fs/cgroup/memory.max');
        $cpuMax = $this->readCgroup('/sys/fs/cgroup/cpu.max');
        $cpuParts = preg_split('/\s+/', $cpuMax) ?: [];
        $cpuCores = null;

        if (count($cpuParts) === 2 && $cpuParts[0] !== 'max' && (int) $cpuParts[1] > 0) {
            $cpuCores = round((int) $cpuParts[0] / (int) $cpuParts[1], 3);
        }

        return [
            'hostname' => gethostname() ?: 'unknown',
            'memory_max' => $memoryMax,
            'memory_bytes' => $memoryMax === 'max' ? null : (int) $memoryMax,
            'cpu_max' => $cpuMax,
            'cpu_cores' => $cpuCores,
        ];
    }

    public function scratchPath(string $runId): string
    {
        return rtrim((string) config('slate.benchmark.scratch_path'), '/').'/'.$runId;
    }

    public function cleanup(string $runId): void
    {
        $path = $this->scratchPath($runId);

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

    private function assertRuntimeAvailable(): void
    {
        $locator = $this->locator();

        if (! $locator->isProvisioned()) {
            throw new RuntimeException('The provisioned ffmpeg and ffprobe binaries are unavailable.');
        }

        if (! is_file($locator->fontPath())) {
            throw new RuntimeException('The benchmark font is unavailable: '.$locator->fontPath());
        }
    }

    private function assertEncoderAvailable(string $encoder): void
    {
        $result = Process::timeout(30)->run([$this->locator()->ffmpegPath(), '-hide_banner', '-encoders']);
        $output = $result->output().$result->errorOutput();
        $encoders = (new FfmpegDiagnostics)->registeredTargetEncoders($output);

        if ($result->failed() || ! in_array($encoder, $encoders, true)) {
            throw new RuntimeException("Required encoder is not registered: {$encoder}");
        }
    }

    private function mediaDurationMs(string $path): int
    {
        $result = Process::timeout(30)->run([
            $this->locator()->ffprobePath(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $duration = trim($result->output());

        if ($result->failed() || ! is_numeric($duration)) {
            throw new RuntimeException('Unable to determine chunk duration: '.trim($result->errorOutput()));
        }

        return (int) round((float) $duration * 1000);
    }

    private function drawtextFilter(string $fontPath, int $chunkIndex): string
    {
        $escapedPath = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "\\'"], $fontPath);

        return "drawtext=fontfile='{$escapedPath}':text='slate chunk {$chunkIndex}':fontcolor=white:fontsize=48:x=20:y=60";
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $operation, ?string $workingDirectory = null): void
    {
        $process = Process::timeout((int) config('slate.benchmark.job_timeout_seconds'));

        if ($workingDirectory !== null) {
            $process = $process->path($workingDirectory);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException("Unable to {$operation}: ".trim($result->errorOutput()));
        }
    }

    private function locator(): FfmpegLocator
    {
        return FfmpegLocator::fromSystem();
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create benchmark scratch directory: {$path}");
        }
    }

    private function fileSize(string $path): int
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Unable to measure file size: {$path}");
        }

        return $size;
    }

    private function readCgroup(string $path): string
    {
        $value = is_file($path) ? file_get_contents($path) : false;

        return is_string($value) ? trim($value) : 'unavailable';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode benchmark measurement: {$path}", previous: $exception);
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Unable to write benchmark measurement: {$path}");
        }
    }

    private function milliseconds(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
