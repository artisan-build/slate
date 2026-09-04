<?php

namespace Tests\Feature\Services;

use App\Models\Ingest;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Services\IngestCleanup;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
}

final class CleanupIngestStorage implements IngestStorage
{
    /** @var array<string, bool> */
    public array $objects = [];

    /** @var list<string> */
    public array $deletedKeys = [];

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

    public function abortMultipartUpload(ObjectKey $objectKey, string $uploadId): void {}

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
        $this->deletedKeys[] = $objectKey->value();
        $this->objects[$objectKey->value()] = false;
    }

    public function temporaryUploadUrl(ObjectKey $objectKey, DateTimeInterface $expiration): array
    {
        throw new \LogicException('Not used by this test.');
    }
}
