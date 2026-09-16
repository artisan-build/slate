<?php

namespace Tests\Feature\Services;

use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Services\IngestCleanup;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class IngestCleanupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prunes_expired_direct_uploads_and_rechecks_failed_rows_for_late_objects(): void
    {
        $this->travelTo('2026-09-04 12:00:00');
        $run = Run::factory()->create();
        $expired = Ingest::expectDirect(
            $run,
            RunObjectKeys::input($run->id, 1, 'expired.bin'),
            now()->subMinute(),
        );
        $future = Ingest::expectDirect(
            $run,
            RunObjectKeys::input($run->id, 2, 'future.bin'),
            now()->addMinute(),
        );
        $storage = new CleanupIngestStorage;
        $storage->objects[$expired->object_key] = true;
        $cleanup = new IngestCleanup($storage);

        $firstPruned = $cleanup->sweepAbandoned();
        $storage->objects[$expired->object_key] = true;
        $secondPruned = $cleanup->sweepAbandoned();

        self::assertSame(1, $firstPruned);
        self::assertSame(1, $secondPruned);
        self::assertSame(IngestStatus::Failed, $expired->fresh()?->status);
        self::assertSame(IngestStatus::Expected, $future->fresh()?->status);
        self::assertSame([$expired->object_key, $expired->object_key], $storage->deletedKeys);
    }

    public function test_late_failure_callback_cannot_overwrite_succeeded_status(): void
    {
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 0, 'source.mp4'),
            'https://source.example/video.mp4',
        );
        $staleFailure = Ingest::query()->findOrFail($ingest->id);
        $successfulWorker = Ingest::query()->findOrFail($ingest->id);

        $successfulWorker->markSucceeded();
        $staleFailure->markFailed();

        self::assertSame(IngestStatus::Succeeded, $staleFailure->status);
        self::assertSame(IngestStatus::Succeeded, $ingest->fresh()?->status);
    }

    public function test_sweep_processes_later_rows_before_reporting_cleanup_failures(): void
    {
        $run = Run::factory()->create();
        $ingests = [
            Ingest::expectDirect(
                $run,
                RunObjectKeys::input($run->id, 3, 'first.bin'),
                now()->subMinute(),
            ),
            Ingest::expectDirect(
                $run,
                RunObjectKeys::input($run->id, 4, 'second.bin'),
                now()->subMinute(),
            ),
        ];
        usort($ingests, static fn (Ingest $first, Ingest $second): int => $first->id <=> $second->id);
        [$failingIngest, $successfulIngest] = $ingests;
        $storage = new CleanupIngestStorage;
        $storage->objects[$failingIngest->object_key] = true;
        $storage->objects[$successfulIngest->object_key] = true;
        $storage->deleteFailures[$failingIngest->object_key] = true;
        Exceptions::fake();

        try {
            (new IngestCleanup($storage))->sweepAbandoned();
            self::fail('Expected the completed sweep to report its cleanup failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Failed to clean 1 abandoned ingests after completing the sweep.',
                $exception->getMessage(),
            );
        }

        self::assertSame([$successfulIngest->object_key], $storage->deletedKeys);
        self::assertTrue($storage->objects[$failingIngest->object_key]);
        self::assertFalse($storage->objects[$successfulIngest->object_key]);
    }

    public function test_sweep_aborts_a_succeeded_ingests_still_inflight_upload_and_keeps_the_object(): void
    {
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 5, 'succeeded.mp4'),
            'https://source.example/video.mp4',
        );
        $ingest->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
            5 * 1024 * 1024,
            'upload-succeeded-inflight',
        );
        $ingest->markSucceeded();
        self::assertNotNull($ingest->fresh()?->multipart_upload_id);
        $alreadyNull = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 7, 'succeeded-without-upload.mp4'),
            'https://source.example/other.mp4',
        );
        $alreadyNull->markSucceeded();
        $storage = new CleanupIngestStorage;
        $storage->objects[$ingest->object_key] = true;

        $pruned = (new IngestCleanup($storage))->sweepAbandoned();

        self::assertSame(1, $pruned);
        self::assertSame(['upload-succeeded-inflight'], $storage->abortedUploadIds);
        self::assertNull($ingest->fresh()?->multipart_upload_id);
        self::assertSame(IngestStatus::Succeeded, $ingest->fresh()?->status);
        self::assertNull($alreadyNull->fresh()?->multipart_upload_id);
        self::assertSame(IngestStatus::Succeeded, $alreadyNull->fresh()?->status);
        self::assertTrue($storage->objects[$ingest->object_key]);
        self::assertSame([], $storage->deletedKeys);
    }

    public function test_sweep_keeps_a_succeeded_ingests_upload_id_when_the_abort_fails(): void
    {
        $run = Run::factory()->create();
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, 6, 'abort-unavailable.mp4'),
            'https://source.example/video.mp4',
        );
        $ingest->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
            5 * 1024 * 1024,
            'upload-abort-unavailable',
        );
        $ingest->markSucceeded();
        $storage = new CleanupIngestStorage;
        $storage->abortFailure = new \RuntimeException('S3 abort unavailable');
        Exceptions::fake();

        try {
            (new IngestCleanup($storage))->sweepAbandoned();
            self::fail('Expected the completed sweep to report its cleanup failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Failed to clean 1 abandoned ingests after completing the sweep.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $storage->abortedUploadIds);
        self::assertNotNull($ingest->fresh()?->multipart_upload_id);
        self::assertSame(IngestStatus::Succeeded, $ingest->fresh()?->status);
    }
}

final class CleanupIngestStorage implements IngestStorage
{
    /** @var array<string, bool> */
    public array $objects = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    /** @var array<string, bool> */
    public array $deleteFailures = [];

    /** @var list<string> */
    public array $abortedUploadIds = [];

    public ?\Throwable $abortFailure = null;

    public function exists(ObjectKey $objectKey): bool
    {
        return $this->objects[$objectKey->value()] ?? false;
    }

    public function size(ObjectKey $objectKey): int
    {
        throw new \LogicException('Not used by this test.');
    }

    public function beginMultipartUpload(ObjectKey $objectKey): string
    {
        throw new \LogicException('Not used by this test.');
    }

    public function abortMultipartUpload(ObjectKey $objectKey, string $uploadId): void
    {
        if ($this->abortFailure !== null) {
            throw $this->abortFailure;
        }

        $this->abortedUploadIds[] = $uploadId;
    }

    public function uploadedParts(ObjectKey $objectKey, string $uploadId): array
    {
        throw new \LogicException('Not used by this test.');
    }

    public function uploadPart(
        ObjectKey $objectKey,
        string $uploadId,
        int $partNumber,
        StreamInterface $stream,
        int $contentLength,
    ): string {
        throw new \LogicException('Not used by this test.');
    }

    public function completeMultipartUpload(
        ObjectKey $objectKey,
        string $uploadId,
        array $etagsByPartNumber,
    ): void {
        throw new \LogicException('Not used by this test.');
    }

    public function delete(ObjectKey $objectKey): void
    {
        if ($this->deleteFailures[$objectKey->value()] ?? false) {
            throw new \RuntimeException('S3 delete unavailable');
        }

        $this->deletedKeys[] = $objectKey->value();
        $this->objects[$objectKey->value()] = false;
    }

    public function temporaryUploadUrl(
        ObjectKey $objectKey,
        DateTimeInterface $expiration,
        bool $singleUse = false,
    ): array {
        throw new \LogicException('Not used by this test.');
    }
}
