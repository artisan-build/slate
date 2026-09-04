<?php

namespace Tests\Feature\Slate\Ingest;

use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\IngestMode;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Slate\Ingest\IngestManager;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class IngestManagerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_source_expectation_before_dispatching_the_ingest_job(): void
    {
        Queue::fake([IngestSource::class]);
        config()->set('slate.execution.ingest_queue', 'ingest');
        $run = Run::factory()->create();
        $sourceUrl = 'https://source.example/video.mp4?signature=secret';
        $storage = $this->mock(IngestStorage::class);

        $ingest = (new IngestManager($storage))->queueSource($run, 3, 'source.mp4', $sourceUrl);

        $expectedKey = RunObjectKeys::input($run->id, 3, 'source.mp4')->value();
        self::assertSame(IngestMode::Source, $ingest->mode);
        self::assertSame(IngestStatus::Expected, $ingest->status);
        self::assertSame($expectedKey, $ingest->object_key);
        self::assertSame($sourceUrl, $ingest->sourceUrl());
        self::assertNotSame(
            $sourceUrl,
            DB::table('ingests')->where('id', $ingest->id)->value('source_url'),
        );
        Queue::assertPushed(
            IngestSource::class,
            fn (IngestSource $job): bool => $job->ingestId === $ingest->id && $job->queue === 'ingest',
        );
    }

    public function test_records_expected_object_and_returns_direct_bucket_put_instructions(): void
    {
        $run = Run::factory()->create();
        $expiration = now()->addMinutes(5);
        $expectedKey = RunObjectKeys::input($run->id, 4, 'narration.mp3');
        $storage = $this->mock(IngestStorage::class);
        $storage->shouldReceive('temporaryUploadUrl')
            ->once()
            ->withArgs(fn (ObjectKey $key, mixed $expires): bool => $key->value() === $expectedKey->value()
                && $expires === $expiration)
            ->andReturn([
                'url' => 'https://bucket.example/presigned-put',
                'headers' => ['Host' => ['bucket.example']],
            ]);

        $upload = (new IngestManager($storage))->presignedPut($run, 4, 'narration.mp3', $expiration);

        $ingest = Ingest::query()->findOrFail($upload->ingestId);
        self::assertSame(IngestMode::Direct, $ingest->mode);
        self::assertSame(IngestStatus::Expected, $ingest->status);
        self::assertSame($expectedKey->value(), $ingest->object_key);
        self::assertSame($expectedKey->value(), $upload->objectKey->value());
        self::assertSame('https://bucket.example/presigned-put', $upload->url);
        self::assertSame(['Host' => ['bucket.example']], $upload->headers);
    }
}
