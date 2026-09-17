<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FinalizeSource;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Services\IngestCleanup;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\FakeDriver;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Source\SourceAdmission;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchPath;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class SourceIngestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_playlist_upload_is_rejected_before_the_driver_is_invoked(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1280000\nhttps://private.example/stream.m3u8\n";
        $source = Source::expectDirect(
            new Subject(SubjectType::Application, 'playlist-owner'),
            'application/vnd.apple.mpegurl',
            strlen($playlist),
            now()->addMinutes(15),
        );
        self::assertTrue($source->claimForFinalization());
        $storage = new SourceJobStorage;
        $storage->objects[$source->object_key] = $playlist;
        $objectStorage = new SourceJobObjectStorage($storage);
        $driver = new FakeDriver;
        $admission = new SourceAdmission(
            $storage,
            $objectStorage,
            resolve(JobScratch::class),
            new InputAdmission,
            RecipeCompiler::fromSystem(),
            $driver,
        );
        $cleanup = new IngestCleanup($storage);

        (new FinalizeSource($source->id))->handle($admission, $cleanup);

        $source->refresh();
        self::assertSame(SourceStatus::Rejected, $source->status);
        self::assertSame(InputRejectionReason::ReferencedMedia, $source->rejection_reason);
        self::assertSame([], $driver->recipes());
        self::assertSame([$source->object_key], $storage->deletedKeys);
        self::assertArrayNotHasKey($source->object_key, $storage->objects);
    }

    public function test_abandoned_upload_is_failed_and_deleted_by_the_ingest_sweep(): void
    {
        $source = Source::expectDirect(
            new Subject(SubjectType::Application, 'abandoned-owner'),
            'video/mp4',
            128,
            now()->subMinute(),
        );
        $storage = new SourceJobStorage;
        $storage->objects[$source->object_key] = str_repeat('x', 128);

        $pruned = (new IngestCleanup($storage))->sweepAbandoned();

        self::assertSame(1, $pruned);
        self::assertSame(SourceStatus::Failed, $source->fresh()?->status);
        self::assertSame([$source->object_key], $storage->deletedKeys);
        self::assertArrayNotHasKey($source->object_key, $storage->objects);
    }

    public function test_late_failure_callback_cannot_delete_a_ready_source(): void
    {
        $source = Source::expectDirect(
            new Subject(SubjectType::Application, 'successful-owner'),
            'video/mp4',
            32,
            now()->addMinutes(15),
        );
        $source->claimForFinalization();
        $source->markReady();
        $storage = new SourceJobStorage;
        $storage->objects[$source->object_key] = str_repeat('x', 32);

        (new IngestCleanup($storage))->failSource($source->id);

        self::assertSame(SourceStatus::Ready, $source->fresh()?->status);
        self::assertSame([], $storage->deletedKeys);
        self::assertArrayHasKey($source->object_key, $storage->objects);
    }

    public function test_sweep_fails_an_ingesting_source_whose_claim_was_never_dispatched(): void
    {
        $this->travelTo('2026-09-08 12:00:00');
        config()->set('slate.ingest.stale_source_ingesting_seconds', 60);
        $staleSource = Source::expectDirect(
            new Subject(SubjectType::Application, 'stale-claim-owner'),
            'video/mp4',
            32,
            now()->addMinutes(15),
        );
        self::assertTrue($staleSource->claimForFinalization());
        $this->travel(30)->seconds();
        $freshSource = Source::expectDirect(
            new Subject(SubjectType::Application, 'fresh-claim-owner'),
            'video/mp4',
            32,
            now()->addMinutes(15),
        );
        self::assertTrue($freshSource->claimForFinalization());
        $storage = new SourceJobStorage;
        $storage->objects[$staleSource->object_key] = str_repeat('x', 32);
        $storage->objects[$freshSource->object_key] = str_repeat('y', 32);

        $pruned = (new IngestCleanup($storage))->sweepAbandoned(now()->addSeconds(31));

        self::assertSame(1, $pruned);
        self::assertSame(SourceStatus::Failed, $staleSource->fresh()?->status);
        self::assertSame([$staleSource->object_key], $storage->deletedKeys);
        self::assertSame(SourceStatus::Ingesting, $freshSource->fresh()?->status);
        self::assertArrayHasKey($freshSource->object_key, $storage->objects);
    }

    public function test_sweep_aborts_a_ready_sources_still_inflight_upload_and_keeps_the_object(): void
    {
        $source = Source::expectRemote(
            new Subject(SubjectType::Application, 'ready-upload-owner'),
            'https://source.example/video.mp4',
        );
        $source->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
            5 * 1024 * 1024,
            'upload-ready-inflight',
        );
        $source->markReady();
        self::assertNotNull($source->fresh()?->multipart_upload_id);
        $alreadyNull = Source::expectRemote(
            new Subject(SubjectType::Application, 'ready-no-upload-owner'),
            'https://source.example/other.mp4',
        );
        $alreadyNull->markReady();
        $storage = new SourceJobStorage;
        $storage->objects[$source->object_key] = str_repeat('x', 5 * 1024 * 1024);

        $pruned = (new IngestCleanup($storage))->sweepAbandoned();

        self::assertSame(1, $pruned);
        self::assertSame(['upload-ready-inflight'], $storage->abortedUploadIds);
        self::assertNull($source->fresh()?->multipart_upload_id);
        self::assertSame(SourceStatus::Ready, $source->fresh()?->status);
        self::assertNull($alreadyNull->fresh()?->multipart_upload_id);
        self::assertSame(SourceStatus::Ready, $alreadyNull->fresh()?->status);
        self::assertArrayHasKey($source->object_key, $storage->objects);
        self::assertSame([], $storage->deletedKeys);
    }

    public function test_sweep_keeps_a_ready_sources_upload_id_when_the_abort_fails(): void
    {
        $source = Source::expectRemote(
            new Subject(SubjectType::Application, 'ready-abort-unavailable-owner'),
            'https://source.example/video.mp4',
        );
        $source->beginUpload(
            new SourceMetadata(5 * 1024 * 1024, 'ETag', '"source-etag"'),
            5 * 1024 * 1024,
            'upload-ready-abort-unavailable',
        );
        $source->markReady();
        $storage = new SourceJobStorage;
        $storage->abortFailure = new \RuntimeException('S3 abort unavailable');

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
        self::assertNotNull($source->fresh()?->multipart_upload_id);
        self::assertSame(SourceStatus::Ready, $source->fresh()?->status);
    }
}

final class SourceJobStorage implements IngestStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    /** @var list<string> */
    public array $abortedUploadIds = [];

    public ?\Throwable $abortFailure = null;

    public function exists(ObjectKey $objectKey): bool
    {
        return array_key_exists($objectKey->value(), $this->objects);
    }

    public function size(ObjectKey $objectKey): int
    {
        return strlen($this->objects[$objectKey->value()] ?? '');
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
        $this->deletedKeys[] = $objectKey->value();
        unset($this->objects[$objectKey->value()]);
    }

    public function temporaryUploadUrl(
        ObjectKey $objectKey,
        DateTimeInterface $expiration,
        bool $singleUse = false,
    ): array {
        throw new \LogicException('Not used by this test.');
    }
}

final readonly class SourceJobObjectStorage implements ObjectStorage
{
    public function __construct(private SourceJobStorage $storage) {}

    public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void
    {
        file_put_contents($localPath->writablePath(), $this->storage->objects[$objectKey->value()]);
    }

    public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void
    {
        throw new \LogicException('Not used by this test.');
    }

    public function exists(ObjectKey $objectKey): bool
    {
        return $this->storage->exists($objectKey);
    }

    public function delete(ObjectKey $objectKey): void
    {
        $this->storage->delete($objectKey);
    }

    public function temporaryUrl(ObjectKey $objectKey, DateTimeInterface $expiration): string
    {
        throw new \LogicException('Not used by this test.');
    }

    public function keysWithinPrefix(string $prefix): array
    {
        throw new \LogicException('Not used by this test.');
    }
}
