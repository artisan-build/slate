<?php

namespace Tests\Feature\Jobs;

use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Slate\Ingest\PermanentIngestException;
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
use Illuminate\Support\Facades\Exceptions;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class IngestSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resumes_uploaded_bucket_parts_and_uses_ingest_queue_runtime(): void
    {
        $this->travelTo('2026-09-04 12:00:00');
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
        $retryUntil = $job->retryUntil();
        $this->travel(30)->minutes();
        $redeliveredJob = unserialize(serialize($job));

        $ingest->refresh();
        self::assertInstanceOf(IngestSource::class, $redeliveredJob);
        self::assertSame('ingest', $job->queue);
        self::assertSame(890, $job->timeout);
        self::assertSame(880, $job->sourceRequestTimeout);
        self::assertSame('2026-09-04 13:06:00', $retryUntil->format('Y-m-d H:i:s'));
        self::assertSame($retryUntil->getTimestamp(), $redeliveredJob->retryUntil()->getTimestamp());
        self::assertFalse(property_exists($job, 'tries'));
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
        $metadata = new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-123');
        $storage = new MemoryIngestStorage;
        $storage->objectExists = true;
        $storage->objectSize = $metadata->bytes;
        $this->app->instance(IngestStorage::class, $storage);

        (new IngestSource($ingest->id))->failed(new \RuntimeException('network unavailable'));

        self::assertSame(IngestStatus::Failed, $ingest->fresh()?->status);
        self::assertSame(['upload-123'], $storage->abortedUploadIds);
        self::assertSame([$ingest->object_key], $storage->deletedKeys);
    }

    public function test_existing_object_does_not_bypass_persisted_source_validator(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 1, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $staleIngest = Ingest::query()->findOrFail($ingest->id);
        $ingest->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"first-etag"'),
            5 * 1024 * 1024,
            'upload-123',
        );
        $storage = new MemoryIngestStorage;
        $storage->objectExists = true;
        $storage->objectSize = 5 * 1024 * 1024;
        $reader = new RecordingSourceReader(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"changed-etag"'),
        );

        $job = (new IngestSource($ingest->id))->withFakeQueueInteractions();

        try {
            $job->middleware()[0]->handle(
                $job,
                fn () => (new ResumableIngestTransfer($storage, $reader))->handle($staleIngest, 160),
            );
            self::fail('Expected changed source metadata to fail permanently.');
        } catch (PermanentIngestException $exception) {
            self::assertSame('The source changed while resuming ingest.', $exception->getMessage());
        }

        $job->assertFailedWith(PermanentIngestException::class);
        self::assertSame(22, $job->maxExceptions);
    }

    public function test_failed_reports_original_before_containing_cleanup_failure(): void
    {
        $this->configureQueueRuntime();
        $ingest = Ingest::factory()->create();
        $ingest->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
            5 * 1024 * 1024,
            'upload-123',
        );
        $storage = new MemoryIngestStorage;
        $storage->abortFailure = new \RuntimeException('S3 abort unavailable');
        $this->app->instance(IngestStorage::class, $storage);
        Exceptions::fake();
        $original = new \DomainException('source request failed');

        (new IngestSource($ingest->id))->failed($original);

        $reported = Exceptions::reported();
        self::assertCount(3, $reported);
        self::assertSame($original, $reported[0]);
        self::assertSame('S3 abort unavailable', $reported[1]->getMessage());
        self::assertSame('Unable to clean all storage for failed ingest.', $reported[2]->getMessage());
    }

    public function test_refresh_stops_before_new_multipart_work_for_a_terminal_ingest(): void
    {
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 2, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $staleIngest = Ingest::query()->findOrFail($ingest->id);
        $ingest->markFailed();
        $storage = new MemoryIngestStorage;
        $reader = new RecordingSourceReader(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
        );

        (new ResumableIngestTransfer($storage, $reader))->handle($staleIngest, 160);

        self::assertSame(0, $storage->multipartUploadsStarted);
        self::assertSame(IngestStatus::Failed, $staleIngest->status);
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

    public bool $objectExists = false;

    public int $objectSize = 0;

    /** @var list<string> */
    public array $abortedUploadIds = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    public ?\Throwable $abortFailure = null;

    public int $multipartUploadsStarted = 0;

    public function exists(ObjectKey $objectKey): bool
    {
        return $this->objectExists;
    }

    public function size(ObjectKey $objectKey): int
    {
        return $this->objectExists ? $this->objectSize : array_sum(array_column($this->parts, 'size'));
    }

    public function beginMultipartUpload(ObjectKey $objectKey): string
    {
        $this->multipartUploadsStarted++;

        return 'upload-123';
    }

    public function abortMultipartUpload(ObjectKey $objectKey, string $uploadId): void
    {
        if ($this->abortFailure !== null) {
            throw $this->abortFailure;
        }

        $this->abortedUploadIds[] = $uploadId;
        $this->parts = [];
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
        $this->objectSize = array_sum(array_column($this->parts, 'size'));
    }

    public function delete(ObjectKey $objectKey): void
    {
        $this->deletedKeys[] = $objectKey->value();
        $this->objectExists = false;
        $this->objectSize = 0;
    }

    public function temporaryUploadUrl(ObjectKey $objectKey, DateTimeInterface $expiration): array
    {
        throw new \LogicException('Not used by this test.');
    }
}
