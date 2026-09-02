<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

#[Signature('slate:verify-object-storage {--bytes=134217728 : Object size in bytes}')]
#[Description('Round-trip an object through the resolved storage seam and report memory usage as JSON.')]
final class VerifyObjectStorageCommand extends Command
{
    public function handle(ObjectStorage $objectStorage, JobScratch $jobScratch): int
    {
        $objectKey = ObjectKey::withinPrefix(
            'live-verification',
            'live-verification/object-storage-'.Str::uuid()->toString().'.bin',
        );
        $disk = null;
        $result = [
            'default_disk' => config('filesystems.default'),
            'configured_driver' => null,
            'resolved_stream_reads' => null,
            'object_key' => $objectKey->value(),
            'object_size_bytes' => null,
            'exists_after_stage_out' => false,
            'content_identical' => false,
            'rss_before_read_bytes' => null,
            'peak_rss_during_read_bytes' => null,
            'rss_growth_during_read_bytes' => null,
            'peak_rss_to_object_ratio' => null,
            'deleted' => false,
            'exists_after_delete' => null,
            'error' => null,
        ];

        try {
            $objectSize = $this->objectSize();
            $disk = Storage::disk();
            $result['object_size_bytes'] = $objectSize;
            $result = $this->withResolvedDiskDetails($result, $disk);
            $result = array_replace(
                $result,
                $jobScratch->run(fn (ScratchDirectory $scratch): array => $this->roundTrip(
                    $objectStorage,
                    $disk,
                    $objectKey,
                    $scratch,
                    $objectSize,
                )),
            );
        } catch (Throwable $exception) {
            $result['error'] = $exception::class.': '.$exception->getMessage();
        } finally {
            if ($disk instanceof Filesystem) {
                try {
                    $disk->delete($objectKey->value());
                    $result['exists_after_delete'] = $disk->exists($objectKey->value());
                    $result['deleted'] = $result['exists_after_delete'] === false;

                    if (! $result['deleted']) {
                        $result = $this->withError($result, 'Object still exists after delete.');
                    }
                } catch (Throwable $exception) {
                    $result = $this->withError($result, 'Cleanup failed: '.$exception->getMessage());
                }
            }
        }

        $this->line((string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return $result['error'] === null && $result['deleted'] ? self::SUCCESS : self::FAILURE;
    }

    private function objectSize(): int
    {
        $bytes = $this->option('bytes');

        if (! is_string($bytes) || ! ctype_digit($bytes) || (int) $bytes < 1) {
            throw new RuntimeException('The --bytes option must be a positive integer.');
        }

        return (int) $bytes;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $result
     * @return array<string, bool|float|int|string|null>
     */
    private function withResolvedDiskDetails(array $result, Filesystem $disk): array
    {
        if (! $disk instanceof FilesystemAdapter) {
            return $result;
        }

        $config = $disk->getConfig();
        $adapter = $disk->getAdapter();
        $result['configured_driver'] = is_string($config['driver'] ?? null) ? $config['driver'] : null;

        if ($adapter instanceof AwsS3V3Adapter) {
            $result['resolved_stream_reads'] = (new ReflectionProperty(AwsS3V3Adapter::class, 'streamReads'))
                ->getValue($adapter);
        }

        return $result;
    }

    /**
     * @return array<string, bool|float|int>
     */
    private function roundTrip(
        ObjectStorage $objectStorage,
        Filesystem $disk,
        ObjectKey $objectKey,
        ScratchDirectory $scratch,
        int $objectSize,
    ): array {
        $sourcePath = $scratch->file('source.bin');
        $destinationPath = $scratch->file('destination.bin');
        $this->writeSource($sourcePath, $objectSize);
        $objectStorage->stageOut($sourcePath, $objectKey);
        $existsAfterStageOut = $disk->exists($objectKey->value());

        if (! $existsAfterStageOut) {
            throw new RuntimeException('Object does not exist after stage-out.');
        }

        [$rssBeforeRead, $peakRss] = $this->measureRssDuring(
            function () use ($objectKey, $objectStorage, $destinationPath): void {
                $objectStorage->stageIn($objectKey, $destinationPath);
            },
            $scratch,
        );
        $contentIdentical = $this->filesAreIdentical($sourcePath, $destinationPath);

        if (! $contentIdentical) {
            throw new RuntimeException('Round-trip content comparison failed.');
        }

        return [
            'exists_after_stage_out' => true,
            'content_identical' => true,
            'rss_before_read_bytes' => $rssBeforeRead,
            'peak_rss_during_read_bytes' => $peakRss,
            'rss_growth_during_read_bytes' => max(0, $peakRss - $rssBeforeRead),
            'peak_rss_to_object_ratio' => round($peakRss / $objectSize, 4),
        ];
    }

    private function writeSource(ScratchPath $path, int $bytes): void
    {
        $stream = fopen($path->writablePath(), 'wb');

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to create the source object.');
        }

        $block = str_repeat('s', 1024 * 1024);

        try {
            $remaining = $bytes;

            while ($remaining > 0) {
                $chunk = substr($block, 0, min($remaining, strlen($block)));

                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Unable to write the complete source object.');
                }

                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{int, int}
     */
    private function measureRssDuring(Closure $read, ScratchDirectory $scratch): array
    {
        $stopPath = $scratch->file('rss-stop')->writablePath();
        $sampler = Process::timeout(300)->start([
            'sh',
            '-c',
            'while [ ! -f "$1" ]; do if [ -r "/proc/$2/status" ]; then awk \'/^VmRSS:/ { print $2 }\' "/proc/$2/status"; else ps -o rss= -p "$2"; fi; sleep 0.02; done',
            'rss-sampler',
            $stopPath,
            (string) getmypid(),
        ]);
        Sleep::usleep(50000);

        try {
            $read();
        } finally {
            touch($stopPath);
            $sampleResult = $sampler->wait();
        }

        if (! $sampleResult->successful()) {
            throw new RuntimeException('RSS sampler failed: '.trim($sampleResult->errorOutput()));
        }

        $samples = preg_split('/\s+/', trim($sampleResult->output()));

        if (! is_array($samples) || $samples === [] || $samples[0] === '') {
            throw new RuntimeException('RSS sampler produced no samples.');
        }

        $rssSamples = array_map(static fn (string $sample): int => (int) $sample * 1024, $samples);

        return [$rssSamples[0], max($rssSamples)];
    }

    private function filesAreIdentical(ScratchPath $sourcePath, ScratchPath $destinationPath): bool
    {
        $source = fopen($sourcePath->readablePath(), 'rb');
        $destination = fopen($destinationPath->readablePath(), 'rb');

        if (! is_resource($source) || ! is_resource($destination)) {
            throw new RuntimeException('Unable to open round-trip files for comparison.');
        }

        try {
            do {
                $sourceChunk = fread($source, 1024 * 1024);
                $destinationChunk = fread($destination, 1024 * 1024);

                if ($sourceChunk === false || $destinationChunk === false) {
                    throw new RuntimeException('Unable to read round-trip files for comparison.');
                }

                if ($sourceChunk !== $destinationChunk) {
                    return false;
                }
            } while ($sourceChunk !== '');

            return true;
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $result
     * @return array<string, bool|float|int|string|null>
     */
    private function withError(array $result, string $error): array
    {
        $result['error'] = is_string($result['error']) ? $result['error'].' '.$error : $error;

        return $result;
    }
}
