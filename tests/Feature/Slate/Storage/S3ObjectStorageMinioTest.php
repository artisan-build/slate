<?php

use App\Jobs\IngestSource;
use App\Jobs\RenderFanoutOperation;
use App\Models\Ingest;
use App\Models\IngestMode;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Services\IngestCleanup;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Ingest\IngestManager;
use App\Slate\Ingest\ResumableIngestTransfer;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Ingest\SourcePart;
use App\Slate\Ingest\SourceReader;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Storage\ScratchDirectory;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MinioConfiguration;

uses(LazilyRefreshDatabase::class);

/**
 * Exercises the real S3 driver, signing, HTTP transport, streamed reads, and multipart-capable writes.
 * It does not exercise Cloudflare R2, Cloud-injected credentials, R2 checksum semantics, or Cloud workers.
 */
test('round-trips and accepts a presigned PUT through MinIO without application byte proxying', function (): void {
    $configuredDisk = config('filesystems.disks.s3');

    expect($configuredDisk)->toBeArray()
        ->and($configuredDisk['stream_reads'] ?? null)->toBeTrue()
        ->and($configuredDisk['throw'] ?? null)->toBeTrue();

    $socket = @fsockopen(
        MinioConfiguration::Host,
        MinioConfiguration::Port,
        $errorCode,
        $errorMessage,
        0.2,
    );

    if (! is_resource($socket)) {
        $message = 'MinIO unavailable at '.MinioConfiguration::Endpoint.'; real S3-driver lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_MINIO'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_MINIO requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    fclose($socket);

    config()->set('filesystems.default', 's3');
    $diskConfig = MinioConfiguration::disk($configuredDisk);

    $client = new S3Client([
        'version' => 'latest',
        'region' => $diskConfig['region'],
        'endpoint' => $diskConfig['endpoint'],
        'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'],
        'credentials' => [
            'key' => $diskConfig['key'],
            'secret' => $diskConfig['secret'],
        ],
    ]);

    if (! $client->doesBucketExist($diskConfig['bucket'])) {
        $client->createBucket(['Bucket' => $diskConfig['bucket']]);
    }

    config()->set('filesystems.disks.s3', $diskConfig);
    Storage::forgetDisk('s3');

    $objectKey = ObjectKey::withinPrefix('minio-tests', 'minio-tests/round-trip.bin');
    $objectStorage = resolve(ObjectStorage::class);
    $expectedBytes = 20 * 1024 * 1024;
    $block = str_repeat('s', 1024 * 1024);

    try {
        resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use (
            $block,
            $expectedBytes,
            $objectKey,
            $objectStorage,
        ): void {
            $sourcePath = $scratch->file('source.bin');
            $sourceStream = fopen($sourcePath->writablePath(), 'wb');

            expect($sourceStream)->toBeResource();

            for ($written = 0; $written < $expectedBytes; $written += strlen($block)) {
                fwrite($sourceStream, $block);
            }

            fclose($sourceStream);
            $objectStorage->stageOut($sourcePath, $objectKey);

            $driverStream = Storage::disk()->readStream($objectKey->value());
            expect($driverStream)->toBeResource();
            $metadata = stream_get_meta_data($driverStream);
            fclose($driverStream);

            expect($metadata['uri'])->not->toStartWith('php://temp');

            $destinationPath = $scratch->file('destination.bin');
            $objectStorage->stageIn($objectKey, $destinationPath);

            expect(filesize($destinationPath->readablePath()))->toBe($expectedBytes)
                ->and(hash_file('sha256', $destinationPath->readablePath()))
                ->toBe(hash_file('sha256', $sourcePath->readablePath()));
        });

        $probe = Process::timeout(180)->run([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            base_path('tests/Fixtures/Storage/minio-memory-probe.php'),
        ]);

        expect($probe->successful())->toBeTrue($probe->errorOutput())
            ->and($probe->output())->toContain('round_trip_bytes=100663296');

        $run = Run::factory()->create();
        $manager = new IngestManager(resolve(IngestStorage::class));
        $expiration = now()->addMinutes(5);
        $upload = $manager->presignedPut($run, 9, 'direct.bin', $expiration);
        $put = Http::withHeaders($upload->headers)
            ->withBody('client bytes sent directly to MinIO')
            ->put($upload->url);

        expect($put->successful())->toBeTrue($put->body())
            ->and(Storage::disk()->get($upload->objectKey->value()))
            ->toBe('client bytes sent directly to MinIO')
            ->and(Ingest::query()->findOrFail($upload->ingestId)->mode)->toBe(IngestMode::Direct);

        $pruned = resolve(IngestCleanup::class)->sweepAbandoned($expiration->addSecond());

        expect($pruned)->toBe(1)
            ->and(Storage::disk()->exists($upload->objectKey->value()))->toBeFalse()
            ->and(Ingest::query()->findOrFail($upload->ingestId)->status)->toBe(IngestStatus::Failed);
    } finally {
        Storage::disk()->delete($objectKey->value());
        Storage::disk()->delete('minio-tests/memory-probe.bin');
        Storage::forgetDisk('s3');
    }
})->group('minio');

function minioFanoutDriver(): Driver
{
    return new class implements Driver
    {
        public function run(CompiledRecipe $recipe): DriverResult
        {
            $command = $recipe->command();

            if (in_array('-show_format', $command, true)) {
                $frameCount = basename($command[count($command) - 1]) === 'output.mp4' ? 48 : 72;
                $properties = [
                    'streams' => [
                        [
                            'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1536,
                            'height' => 1024, 'avg_frame_rate' => '24/1', 'pix_fmt' => 'yuv420p',
                            'color_range' => 'tv', 'nb_frames' => (string) $frameCount,
                        ],
                        ['codec_type' => 'audio', 'codec_name' => 'aac'],
                    ],
                    'format' => ['duration' => number_format($frameCount / 24, 6, '.', '')],
                ];

                return new DriverResult(json_encode($properties, JSON_THROW_ON_ERROR));
            }

            file_put_contents($command[count($command) - 1], 'rendered through a MinIO-backed handoff');

            return new DriverResult;
        }
    };
}

test('moves queued work and resumes a partially uploaded ingest through MinIO', function (): void {
    $socket = @fsockopen(
        MinioConfiguration::Host,
        MinioConfiguration::Port,
        $errorCode,
        $errorMessage,
        0.2,
    );

    if (! is_resource($socket)) {
        $message = 'MinIO unavailable at '.MinioConfiguration::Endpoint.'; queued handoff lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_MINIO'), FILTER_VALIDATE_BOOL)) {
            test()->fail($message.' SLATE_REQUIRE_MINIO requires this lane to run.');
        }

        test()->markTestSkipped($message);
    }

    fclose($socket);
    $configuredDisk = config('filesystems.disks.s3');
    expect($configuredDisk)->toBeArray();
    $diskConfig = MinioConfiguration::disk($configuredDisk);
    $client = new S3Client([
        'version' => 'latest',
        'region' => $diskConfig['region'],
        'endpoint' => $diskConfig['endpoint'],
        'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'],
        'credentials' => ['key' => $diskConfig['key'], 'secret' => $diskConfig['secret']],
    ]);

    if (! $client->doesBucketExist($diskConfig['bucket'])) {
        $client->createBucket(['Bucket' => $diskConfig['bucket']]);
    }

    config()->set('filesystems.default', 's3');
    config()->set('filesystems.disks.s3', $diskConfig);
    Storage::forgetDisk('s3');
    $runId = FanoutRunId::fromString('66666666-6666-4666-8666-666666666666');
    $prefix = $runId->prefix();
    $inputKey = ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0000.mp4');
    $outputKey = ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4');
    $receiptKey = ObjectKey::withinPrefix($prefix, $prefix.'/work/receipts/0000.json');
    $stored = new StoredVideo($inputKey, 1536, 1024, 24, 72, 3.0, QualityTier::Delivery, 24);
    $operation = new BucketVideoTrim(0, $stored, 0, 48, $outputKey);
    $objectStorage = resolve(ObjectStorage::class);

    try {
        Storage::disk()->put($inputKey->value(), 'source bytes that exist only in MinIO');

        (new RenderFanoutOperation($runId, $operation))->handle(
            $objectStorage,
            resolve(JobScratch::class),
            RecipeCompiler::fromSystem(),
            minioFanoutDriver(),
            resolve(FanoutStorageGuard::class),
        );

        expect($objectStorage->exists($outputKey))->toBeTrue()
            ->and($objectStorage->exists($receiptKey))->toBeTrue()
            ->and(Storage::disk()->get($outputKey->value()))->toBe('rendered through a MinIO-backed handoff');

        $objectStorage->delete($inputKey);
        $objectStorage->delete($outputKey);
        $objectStorage->delete($receiptKey);

        expect($objectStorage->exists($inputKey))->toBeFalse()
            ->and($objectStorage->exists($outputKey))->toBeFalse()
            ->and($objectStorage->exists($receiptKey))->toBeFalse();

        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $sourceKey = ObjectKey::withinPrefix('minio-tests', 'minio-tests/resumable-source.bin');
        $sourceBytes = (10 * 1024 * 1024) + 17;
        $sourceStream = fopen('php://temp/maxmemory:1048576', 'w+b');
        expect($sourceStream)->toBeResource();

        for ($remaining = $sourceBytes; $remaining > 0; $remaining -= min($remaining, 1024 * 1024)) {
            $bytes = min($remaining, 1024 * 1024);
            fwrite($sourceStream, str_repeat('r', $bytes));
        }

        rewind($sourceStream);
        Storage::disk()->writeStream($sourceKey->value(), $sourceStream);
        fclose($sourceStream);
        $run = Run::factory()->create();
        $targetKey = RunObjectKeys::input($run->id, 2, 'resumed.bin');
        $sourceUrl = Storage::disk()->temporaryUrl($sourceKey->value(), now()->addMinutes(5));
        $ingest = Ingest::expectSource($run, $targetKey, $sourceUrl);
        $ingestStorage = resolve(IngestStorage::class);
        $sourceReader = resolve(SourceReader::class);
        $metadata = $sourceReader->metadata($sourceUrl, 60);
        $uploadId = $ingestStorage->beginMultipartUpload($targetKey);
        $firstPart = $sourceReader->read($sourceUrl, $metadata, 0, (5 * 1024 * 1024) - 1, 60);

        try {
            $ingestStorage->uploadPart(
                $targetKey,
                $uploadId,
                1,
                $firstPart->stream,
                $firstPart->bytes,
            );
        } finally {
            $firstPart->close();
        }

        $ingest->beginUpload($metadata, 5 * 1024 * 1024, $uploadId);
        $recordingReader = new class($sourceReader) implements SourceReader
        {
            /** @var list<array{int, int}> */
            public array $ranges = [];

            public function __construct(private readonly SourceReader $reader) {}

            public function metadata(string $sourceUrl, int $timeoutSeconds): SourceMetadata
            {
                return $this->reader->metadata($sourceUrl, $timeoutSeconds);
            }

            public function read(
                string $sourceUrl,
                SourceMetadata $metadata,
                int $startByte,
                int $endByte,
                int $timeoutSeconds,
            ): SourcePart {
                $this->ranges[] = [$startByte, $endByte];

                return $this->reader->read($sourceUrl, $metadata, $startByte, $endByte, $timeoutSeconds);
            }
        };

        (new IngestSource($ingest->id))->handle(new ResumableIngestTransfer($ingestStorage, $recordingReader));

        expect($recordingReader->ranges)->toBe([
            [5 * 1024 * 1024, (10 * 1024 * 1024) - 1],
            [10 * 1024 * 1024, $sourceBytes - 1],
        ])->and(Storage::disk()->size($targetKey->value()))->toBe($sourceBytes)
            ->and(hash('sha256', Storage::disk()->get($targetKey->value())))
            ->toBe(hash('sha256', Storage::disk()->get($sourceKey->value())));

        Storage::disk()->delete($targetKey->value());

        $failureRun = Run::factory()->create();
        $failureKey = RunObjectKeys::input($failureRun->id, 3, 'failed.bin');
        Storage::disk()->put($failureKey->value(), 'completed object requiring failure cleanup');
        $failureIngest = Ingest::expectSource($failureRun, $failureKey, $sourceUrl);
        $failureUploadId = $ingestStorage->beginMultipartUpload($failureKey);
        $failurePart = $sourceReader->read($sourceUrl, $metadata, 0, (5 * 1024 * 1024) - 1, 60);

        try {
            $ingestStorage->uploadPart(
                $failureKey,
                $failureUploadId,
                1,
                $failurePart->stream,
                $failurePart->bytes,
            );
        } finally {
            $failurePart->close();
        }

        $failureIngest->beginUpload($metadata, 5 * 1024 * 1024, $failureUploadId);
        (new IngestSource($failureIngest->id))->failed(new RuntimeException('terminal source failure'));
        $failureUploads = $client->listMultipartUploads([
            'Bucket' => $diskConfig['bucket'],
            'Prefix' => $failureKey->value(),
        ])['Uploads'] ?? [];

        expect($failureIngest->fresh()?->status)->toBe(IngestStatus::Failed)
            ->and(Storage::disk()->exists($failureKey->value()))->toBeFalse()
            ->and($failureUploads)->toBe([]);

        $compensationRun = Run::factory()->create();
        $compensationKey = RunObjectKeys::input($compensationRun->id, 4, 'compensated.bin');
        $compensationIngest = Ingest::expectSource($compensationRun, $compensationKey, $sourceUrl);
        Ingest::saving(static function (Ingest $saving) use ($compensationIngest): void {
            if ($saving->id === $compensationIngest->id && $saving->multipart_upload_id !== null) {
                throw new RuntimeException('simulated ingest state persistence failure');
            }
        });

        try {
            expect(fn () => (new ResumableIngestTransfer($ingestStorage, $sourceReader))
                ->handle($compensationIngest, 60))
                ->toThrow(RuntimeException::class, 'simulated ingest state persistence failure');
        } finally {
            Ingest::flushEventListeners();
        }

        $compensationUploads = $client->listMultipartUploads([
            'Bucket' => $diskConfig['bucket'],
            'Prefix' => $compensationKey->value(),
        ])['Uploads'] ?? [];

        expect($compensationUploads)->toBe([])
            ->and(Storage::disk()->exists($compensationKey->value()))->toBeFalse();

        Storage::disk()->delete($sourceKey->value());
    } finally {
        Storage::disk()->deleteDirectory($prefix);
        Storage::forgetDisk('s3');
    }
})->group('minio');
