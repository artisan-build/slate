<?php

namespace Tests\Feature\Jobs;

use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Slate\Ingest\ResumableIngestTransfer;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Ingest\SourcePart;
use App\Slate\Ingest\SourceReader;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use DateTimeInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class IngestSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resumes_uploaded_bucket_parts_and_uses_ingest_queue_runtime(): void
    {
        $this->configureQueueRuntime();
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 7, 'source.mp4'),
            'https://source.example/video.mp4?signature=secret',
        );
        $metadata = new SourceMetadata((5 * 1024 * 1024) + 4, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-123');
        $storage = new MemoryIngestStorage;
        $storage->parts[1] = ['etag' => '"part-1"', 'size' => 5 * 1024 * 1024];
        $reader = new RecordingSourceReader($metadata);
        $this->app->instance(IngestStorage::class, $storage);
        $this->app->instance(SourceReader::class, $reader);
        $job = new IngestSource($ingest->id);

        $job->handle($this->app->make(ResumableIngestTransfer::class));

        $ingest->refresh();
        self::assertSame('ingest', $job->queue);
        self::assertSame(890, $job->timeout);
        self::assertSame(880, $job->sourceRequestTimeout);
        self::assertSame([[5 * 1024 * 1024, (5 * 1024 * 1024) + 3, 880]], $reader->ranges);
        self::assertSame([1 => '"part-1"', 2 => '"part-2"'], $storage->completedParts);
        self::assertSame(IngestStatus::Succeeded, $ingest->status);
        self::assertNull($ingest->source_url);
        self::assertSame([], \queuedJobAbsolutePathViolations($job));
        self::assertSame([], \queuedJobKnownCompiledVocabularyViolations($job));
    }

    public function test_terminal_job_failure_records_failed_ingest(): void
    {
        $this->configureQueueRuntime();
        $ingest = Ingest::factory()->create();

        (new IngestSource($ingest->id))->failed(new \RuntimeException('network unavailable'));

        self::assertSame(IngestStatus::Failed, $ingest->fresh()?->status);
    }

    private function configureQueueRuntime(): void
    {
        config()->set('queue.default', 'ingest_test');
        config()->set('queue.connections.ingest_test', [
            'driver' => 'database',
            'retry_after' => 90,
            'queues' => [
                'default' => ['retry_after' => 180],
                'ingest' => ['retry_after' => 900],
            ],
        ]);
    }
}

final class RecordingSourceReader implements SourceReader
{
    /** @var list<array{int, int, int}> */
    public array $ranges = [];

    public function __construct(private readonly SourceMetadata $sourceMetadata) {}

    public function metadata(string $sourceUrl, int $timeoutSeconds): SourceMetadata
    {
        return $this->sourceMetadata;
    }

    public function read(
        string $sourceUrl,
        SourceMetadata $metadata,
        int $startByte,
        int $endByte,
        int $timeoutSeconds,
    ): SourcePart {
        $this->ranges[] = [$startByte, $endByte, $timeoutSeconds];

        return new SourcePart(Utils::streamFor('remaining source bytes'), $endByte - $startByte + 1);
    }
}

final class MemoryIngestStorage implements IngestStorage
{
    /** @var array<int, array{etag: string, size: int}> */
    public array $parts = [];

    /** @var array<int, string> */
    public array $completedParts = [];

    private bool $objectExists = false;

    public function exists(ObjectKey $objectKey): bool
    {
        return $this->objectExists;
    }

    public function size(ObjectKey $objectKey): int
    {
        return array_sum(array_column($this->parts, 'size'));
    }

    public function beginMultipartUpload(ObjectKey $objectKey): string
    {
        return 'upload-123';
    }

    public function uploadedParts(ObjectKey $objectKey, string $uploadId): array
    {
        return $this->parts;
    }

    public function uploadPart(
        ObjectKey $objectKey,
        string $uploadId,
        int $partNumber,
        StreamInterface $stream,
        int $contentLength,
    ): string {
        $etag = '"part-'.$partNumber.'"';
        $this->parts[$partNumber] = ['etag' => $etag, 'size' => $contentLength];

        return $etag;
    }

    public function completeMultipartUpload(
        ObjectKey $objectKey,
        string $uploadId,
        array $etagsByPartNumber,
    ): void {
        $this->completedParts = $etagsByPartNumber;
        $this->objectExists = true;
    }

    public function temporaryUploadUrl(ObjectKey $objectKey, DateTimeInterface $expiration): array
    {
        throw new \LogicException('Not used by this test.');
    }
}
