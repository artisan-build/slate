<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Jobs\FinalizeSource;
use App\Jobs\IngestRemoteSource;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Source\SourceApiErrorReason;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use ArtisanBuild\BuiltForCloud\SubjectType;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use Tests\Support\WithSlateSourcesCredentials;
use Tests\TestCase;

final class SourceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithSlateSourcesCredentials;

    public function test_upload_submit_returns_201_with_server_owned_presigned_object(): void
    {
        $this->travelTo('2026-09-08 12:00:00');
        Queue::fake([FinalizeSource::class, IngestRemoteSource::class]);
        $storage = new SourceControllerStorage;
        $this->app->instance(IngestStorage::class, $storage);
        $credential = $this->mintCredential([
            'subject_ref' => 'private-owner-reference',
        ]);

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'upload' => ['content_type' => 'video/mp4', 'size' => 4096],
        ]);

        $response->assertCreated()->assertJsonStructure(['id', 'put_url', 'headers', 'expires_at', 'object_key']);
        $source = Source::query()->findOrFail($response->json('id'));
        $response->assertExactJson([
            'id' => $source->id,
            'put_url' => 'https://bucket.example.test/presigned-put',
            'headers' => ['If-None-Match' => ['*']],
            'expires_at' => '2026-09-08T12:15:00.000000Z',
            'object_key' => 'slate-sources/'.$source->id.'/source',
        ]);
        self::assertTrue(Str::isUuid($source->id));
        self::assertSame(SubjectType::Installation, $source->owner_subject_type);
        self::assertSame('private-owner-reference', $source->owner_subject_ref);
        self::assertSame(SourceStatus::Pending, $source->status);
        self::assertSame(4096, $source->bytes);
        self::assertSame('video/mp4', $source->content_type);
        self::assertStringNotContainsString('private-owner-reference', $source->object_key);
        self::assertSame([$source->object_key], $storage->presignedKeys);
        Queue::assertNothingPushed();
    }

    public function test_url_submit_returns_202_and_queues_ingest_for_the_authenticated_subject(): void
    {
        Queue::fake([IngestRemoteSource::class]);
        $this->app->instance(IngestStorage::class, new SourceControllerStorage);
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'url' => 'https://media.example.test/video.mp4?signature=secret',
        ]);

        $response->assertAccepted()->assertJsonStructure(['id']);
        $source = Source::query()->findOrFail($response->json('id'));
        self::assertSame(SourceStatus::Ingesting, $source->status);
        self::assertSame('test-subject', $source->owner_subject_ref);
        self::assertSame('https://media.example.test/video.mp4?signature=secret', $source->sourceUrl());
        Queue::assertPushed(
            IngestRemoteSource::class,
            fn (IngestRemoteSource $job): bool => $job->sourceId === $source->id && $job->queue === 'ingest',
        );
    }

    #[DataProvider('unsupportedFieldProvider')]
    public function test_client_owned_identifiers_return_422_as_unsupported_fields(array $payload): void
    {
        Queue::fake([FinalizeSource::class, IngestRemoteSource::class]);
        $this->app->instance(IngestStorage::class, new SourceControllerStorage);
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->postJson('/api/v1/sources', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('reason', SourceApiErrorReason::InvalidSource->value);
        self::assertSame(0, Source::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_foreign_and_genuine_missing_sources_return_byte_identical_404_bodies(): void
    {
        $source = Source::factory()->create(['owner_subject_ref' => 'tenant-a']);
        $this->app->instance(IngestStorage::class, new SourceControllerStorage);
        $credential = $this->mintCredential([
            'subject_ref' => 'tenant-b',
        ]);

        $foreign = $this->actingAsCredential($credential)->getJson('/api/v1/sources/'.$source->id);
        $missing = $this->getJson('/api/v1/sources/11111111-1111-4111-8111-111111111111');

        $foreign->assertNotFound();
        $missing->assertNotFound();
        self::assertSame($missing->getContent(), $foreign->getContent());
        self::assertSame(
            '{"message":"The source was not found.","reason":"source_not_found"}',
            $foreign->getContent(),
        );
    }

    public function test_first_status_poll_dispatches_upload_finalization_once_and_reports_ingesting(): void
    {
        Queue::fake([FinalizeSource::class]);
        $source = Source::factory()->create();
        $storage = new SourceControllerStorage;
        $storage->objects[$source->object_key] = true;
        $this->app->instance(IngestStorage::class, $storage);
        $credential = $this->mintCredential();

        $first = $this->actingAsCredential($credential)->getJson('/api/v1/sources/'.$source->id);
        $second = $this->getJson('/api/v1/sources/'.$source->id);

        $first->assertOk()->assertJsonPath('status', 'ingesting');
        $second->assertOk()->assertJsonPath('status', 'ingesting');
        self::assertSame(SourceStatus::Ingesting, $source->fresh()?->status);
        self::assertSame(1, $storage->existenceChecks);
        Queue::assertPushed(FinalizeSource::class, 1);
        Queue::assertPushed(FinalizeSource::class, fn (FinalizeSource $job): bool => $job->sourceId === $source->id);
    }

    public function test_status_returns_the_explicit_source_shape(): void
    {
        $source = Source::factory()->create([
            'status' => SourceStatus::Ready,
            'bytes' => 9876,
            'content_type' => 'video/webm',
        ]);
        $this->app->instance(IngestStorage::class, new SourceControllerStorage);
        $credential = $this->mintCredential();

        $response = $this->actingAsCredential($credential)->getJson('/api/v1/sources/'.$source->id);

        $response->assertOk()->assertExactJson([
            'id' => $source->id,
            'status' => 'ready',
            'rejection_reason' => null,
            'bytes' => 9876,
            'content_type' => 'video/webm',
        ]);
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_each_source_endpoint_returns_401_without_a_credential(string $method, string $path): void
    {
        $response = $this->json($method, $path, [
            'upload' => ['content_type' => 'video/mp4', 'size' => 10],
        ]);

        $response->assertUnauthorized();
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_each_source_endpoint_returns_401_with_a_non_consumption_credential(string $method, string $path): void
    {
        $credential = $this->mintNonSlateCredential();

        $response = $this->actingAsCredential($credential)->json($method, $path, [
            'upload' => ['content_type' => 'video/mp4', 'size' => 10],
        ]);

        $response->assertUnauthorized();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedFieldProvider(): array
    {
        return [
            'client id' => [[
                'id' => '22222222-2222-4222-8222-222222222222',
                'upload' => ['content_type' => 'video/mp4', 'size' => 10],
            ]],
            'client object key' => [[
                'object_key' => 'another-subject/private.mp4',
                'upload' => ['content_type' => 'video/mp4', 'size' => 10],
            ]],
            'nested client object key' => [[
                'upload' => [
                    'content_type' => 'video/mp4',
                    'size' => 10,
                    'object_key' => 'another-subject/private.mp4',
                ],
            ]],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function protectedEndpointProvider(): array
    {
        return [
            'submit' => ['POST', '/api/v1/sources'],
            'status' => ['GET', '/api/v1/sources/11111111-1111-4111-8111-111111111111'],
        ];
    }
}

final class SourceControllerStorage implements IngestStorage
{
    /** @var array<string, bool> */
    public array $objects = [];

    /** @var list<string> */
    public array $presignedKeys = [];

    public int $existenceChecks = 0;

    public function exists(ObjectKey $objectKey): bool
    {
        $this->existenceChecks++;

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
        $this->objects[$objectKey->value()] = false;
    }

    public function temporaryUploadUrl(
        ObjectKey $objectKey,
        DateTimeInterface $expiration,
        bool $singleUse = false,
    ): array {
        $this->presignedKeys[] = $objectKey->value();

        return [
            'url' => 'https://bucket.example.test/presigned-put',
            'headers' => $singleUse ? ['If-None-Match' => ['*']] : [],
        ];
    }
}
