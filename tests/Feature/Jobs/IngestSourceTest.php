<?php

namespace Tests\Feature\Jobs;

use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Models\RunStatus;
use App\Services\IngestCleanup;
use App\Slate\Http\DestinationGuard;
use App\Slate\Http\ResolvedDestination;
use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\ResumableIngestTransfer;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Ingest\SourcePart;
use App\Slate\Ingest\SourceReader;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectedException;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Webhook\HostnameResolver;
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
        $this->app->instance(HostnameResolver::class, new PublicSourceHostnameResolver);
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
        self::assertSame([
            [0, (64 * 1024) - 1, 880],
            [5 * 1024 * 1024, (5 * 1024 * 1024) + 3, 880],
        ], $reader->ranges);
        self::assertSame([1 => '"part-1"', 2 => '"part-2"'], $storage->completedParts);
        self::assertSame(IngestStatus::Succeeded, $ingest->status);
        self::assertNull($ingest->source_url);
        self::assertSame([], \queuedJobAbsolutePathViolations($job));
        self::assertSame([], \queuedJobKnownCompiledVocabularyViolations($job));
    }

    public function test_terminal_job_failure_records_failed_ingest(): void
    {
        $this->configureQueueRuntime();
        $ingest = Ingest::factory()->create(['source_url' => 'https://source.example/video.mp4']);
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
                fn () => $this->transfer($storage, $reader)->handle($staleIngest, 160),
            );
            self::fail('Expected changed source metadata to fail permanently.');
        } catch (PermanentIngestException $exception) {
            self::assertSame('The source changed while resuming ingest.', $exception->getMessage());
        }

        $job->assertFailedWith(PermanentIngestException::class);
        self::assertSame(22, $job->maxExceptions);
    }

    // Reproduces a live production defect (judge finding at df1592c, mutation
    // sweep 2026-09-16): the existing-object shortcut in
    // ResumableIngestTransfer::handle() returns before completing or
    // aborting a persisted multipart upload, and transitionTo() then nulls
    // multipart_upload_id on Succeeded while sweepAbandoned() never
    // revisits Succeeded rows — the upload can never be aborted and its
    // parts are billed forever. The assertion is the design-neutral
    // invariant, not a fix design: the upload must either still be
    // abortable (its id survived) or already have been aborted in storage.
    public function test_a_source_ingest_whose_object_already_exists_cannot_end_succeeded_with_an_unabortable_upload(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 1, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $metadata = new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-in-flight');
        $storage = new MemoryIngestStorage;
        $storage->objectExists = true;
        $storage->objectSize = $metadata->bytes;
        $reader = new RecordingSourceReader($metadata);

        $this->transfer($storage, $reader)->handle($ingest, 160);
        $ingest->markSucceeded();

        $fresh = $ingest->fresh();
        self::assertTrue(
            $fresh?->multipart_upload_id !== null || $storage->abortedUploadIds !== [],
            'A succeeded ingest left its in-flight multipart upload unabortable.',
        );
    }

    // Pins the transfer-side half of the upload-id lifecycle (ledger key
    // ingest-completed-upload-id-lifecycle): a normal completion drops the id
    // in the transfer, so the success row never reaches the hourly sweep and
    // abort-after-complete never has to answer for the happy path.
    public function test_a_completed_transfer_drops_the_upload_id_so_the_success_row_never_reaches_the_sweep(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 3, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $metadata = new SourceMetadata((5 * 1024 * 1024) + 4, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-in-flight');
        $storage = new MemoryIngestStorage;
        $storage->parts[1] = ['etag' => '"part-1"', 'size' => 5 * 1024 * 1024];
        $reader = new RecordingSourceReader($metadata);

        $this->transfer($storage, $reader)->handle($ingest, 160);
        $ingest->markSucceeded();

        self::assertSame(IngestStatus::Succeeded, $ingest->fresh()?->status);
        self::assertNull($ingest->fresh()?->multipart_upload_id);
        self::assertSame([], $storage->abortedUploadIds);
        self::assertSame(0, (new IngestCleanup($storage))->sweepAbandoned());
    }

    // When completion is not acknowledged but the object exists with the
    // expected size, the upload may still be live: the transfer must abort it
    // before dropping the id, because the id is the only handle to its
    // billable parts.
    public function test_a_completion_with_unknown_outcome_aborts_the_live_upload_before_dropping_its_id(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 4, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $metadata = new SourceMetadata((5 * 1024 * 1024) + 4, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-unacknowledged');
        $storage = new MemoryIngestStorage;
        $storage->parts[1] = ['etag' => '"part-1"', 'size' => 5 * 1024 * 1024];
        $storage->completeFailure = new \RuntimeException('timeout after server-side completion');
        $reader = new RecordingSourceReader($metadata);

        $this->transfer($storage, $reader)->handle($ingest, 160);
        $ingest->markSucceeded();

        self::assertSame(IngestStatus::Succeeded, $ingest->fresh()?->status);
        self::assertSame(['upload-unacknowledged'], $storage->abortedUploadIds);
        self::assertNull($ingest->fresh()?->multipart_upload_id);
    }

    // A fallback abort failure must keep the id: the hourly sweep is the
    // backstop that retries the abort, so dropping the handle on a failed
    // abort would orphan the still-live upload's billable parts.
    public function test_a_fallback_abort_failure_keeps_the_upload_id_for_the_sweep(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 5, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $metadata = new SourceMetadata((5 * 1024 * 1024) + 4, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-abort-unavailable');
        $storage = new MemoryIngestStorage;
        $storage->parts[1] = ['etag' => '"part-1"', 'size' => 5 * 1024 * 1024];
        $storage->completeFailure = new \RuntimeException('timeout after server-side completion');
        $storage->abortFailure = new \RuntimeException('S3 abort unavailable');
        $reader = new RecordingSourceReader($metadata);

        try {
            $this->transfer($storage, $reader)->handle($ingest, 160);
            self::fail('Expected the unacknowledged completion to surface its abort failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('S3 abort unavailable', $exception->getMessage());
        }

        self::assertNotNull($ingest->fresh()?->multipart_upload_id);
    }

    // A completion that genuinely failed must propagate: without the
    // exists-and-size re-verification, a rejected completion would be treated
    // as an unacknowledged success and an ingest would be marked Succeeded
    // over a missing object.
    public function test_a_genuinely_failed_completion_propagates_and_keeps_the_upload_id(): void
    {
        config()->set('slate.ingest.part_size_bytes', 5 * 1024 * 1024);
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 6, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $metadata = new SourceMetadata((5 * 1024 * 1024) + 4, 'ETag', '"source-etag"');
        $ingest->beginUpload($metadata, 5 * 1024 * 1024, 'upload-rejected');
        $storage = new MemoryIngestStorage;
        $storage->parts[1] = ['etag' => '"part-1"', 'size' => 5 * 1024 * 1024];
        $storage->completeFailure = new \RuntimeException('completion rejected');
        $storage->completeLandsObject = false;
        $reader = new RecordingSourceReader($metadata);

        try {
            $this->transfer($storage, $reader)->handle($ingest, 160);
            self::fail('Expected the failed completion to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('completion rejected', $exception->getMessage());
        }

        self::assertNotNull($ingest->fresh()?->multipart_upload_id);
        self::assertSame(IngestStatus::Uploading, $ingest->fresh()?->status);
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

        $this->transfer($storage, $reader)->handle($staleIngest, 160);

        self::assertSame(0, $storage->multipartUploadsStarted);
        self::assertSame(IngestStatus::Failed, $staleIngest->status);
    }

    public function test_rejects_an_oversized_source_before_starting_multipart_upload(): void
    {
        config()->set('slate.ingest.maximum_source_bytes', 1024);
        $ingest = Ingest::factory()->create(['source_url' => 'https://source.example/video.mp4']);
        $storage = new MemoryIngestStorage;
        $reader = new RecordingSourceReader(new SourceMetadata(1025, 'ETag', '"large"'));

        try {
            (new IngestSource($ingest->id))->handle(
                $this->transfer($storage, $reader),
            );
            self::fail('Expected oversized source rejection.');
        } catch (InputRejectedException $exception) {
            self::assertSame(InputRejectionReason::SourceTooLarge, $exception->reason);
        }

        self::assertSame(0, $storage->multipartUploadsStarted);
        self::assertSame([], $reader->ranges);
        self::assertSame(RunStatus::Failed, $ingest->run()->firstOrFail()->status);
        self::assertSame(
            InputRejectionReason::SourceTooLarge,
            $ingest->run()->firstOrFail()->rejection_reason,
        );
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

    private function transfer(IngestStorage $storage, SourceReader $reader): ResumableIngestTransfer
    {
        return new ResumableIngestTransfer(
            $storage,
            $reader,
            new InputAdmission,
            new DestinationGuard(new PublicSourceHostnameResolver),
        );
    }
}

final class RecordingSourceReader implements SourceReader
{
    /** @var list<array{int, int, int}> */
    public array $ranges = [];

    public function __construct(
        private readonly SourceMetadata $sourceMetadata,
        private readonly string $initialBytes = "\0\0\0\x18ftypisom\0\0\0\0isom",
    ) {}

    public function metadata(ResolvedDestination $source, int $timeoutSeconds): SourceMetadata
    {
        return $this->sourceMetadata;
    }

    public function read(
        ResolvedDestination $source,
        SourceMetadata $metadata,
        int $startByte,
        int $endByte,
        int $timeoutSeconds,
    ): SourcePart {
        $this->ranges[] = [$startByte, $endByte, $timeoutSeconds];

        return new SourcePart(
            Utils::streamFor($startByte === 0 ? $this->initialBytes : 'remaining source bytes'),
            $endByte - $startByte + 1,
        );
    }
}

final readonly class PublicSourceHostnameResolver implements HostnameResolver
{
    public function resolve(string $hostname): array
    {
        return ['93.184.216.34'];
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

    public ?\Throwable $completeFailure = null;

    public bool $completeLandsObject = true;

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

        if ($this->completeFailure !== null && ! $this->completeLandsObject) {
            throw $this->completeFailure;
        }

        $this->objectExists = true;
        $this->objectSize = array_sum(array_column($this->parts, 'size'));

        if ($this->completeFailure !== null) {
            throw $this->completeFailure;
        }
    }

    public function delete(ObjectKey $objectKey): void
    {
        $this->deletedKeys[] = $objectKey->value();
        $this->objectExists = false;
        $this->objectSize = 0;
    }

    public function temporaryUploadUrl(
        ObjectKey $objectKey,
        DateTimeInterface $expiration,
        bool $singleUse = false,
    ): array {
        throw new \LogicException('Not used by this test.');
    }
}
