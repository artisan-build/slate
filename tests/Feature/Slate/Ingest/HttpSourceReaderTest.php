<?php

namespace Tests\Feature\Slate\Ingest;

use App\Jobs\IngestSource;
use App\Slate\Ingest\HttpSourceReader;
use App\Slate\Ingest\PermanentIngestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpSourceReaderTest extends TestCase
{
    public function test_reads_validated_ranges_without_materializing_the_whole_source(): void
    {
        $sourceUrl = 'https://source.example/video.mp4';
        Http::preventStrayRequests();
        Http::fake([
            $sourceUrl => Http::sequence()
                ->push('m', 206, [
                    'Content-Range' => 'bytes 0-0/12',
                    'ETag' => '"stable-source"',
                ])
                ->push('source', 206, [
                    'Content-Range' => 'bytes 6-11/12',
                    'ETag' => '"stable-source"',
                ]),
        ]);
        $reader = new HttpSourceReader;

        $metadata = $reader->metadata($sourceUrl, 30);
        $part = $reader->read($sourceUrl, $metadata, 6, 11, 30);
        $body = $part->stream->getContents();
        $part->close();

        self::assertSame(12, $metadata->bytes);
        self::assertSame('ETag', $metadata->validatorHeader);
        self::assertSame('"stable-source"', $metadata->validator);
        self::assertSame('source', $body);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Range', 'bytes=6-11')
            && $request->hasHeader('If-Match', '"stable-source"'));
    }

    public function test_rejects_a_source_that_ignores_range_requests(): void
    {
        $sourceUrl = 'https://source.example/no-ranges.mp4';
        Http::preventStrayRequests();
        Http::fake([$sourceUrl => Http::response('whole source', 200)]);

        $this->expectException(PermanentIngestException::class);
        $this->expectExceptionMessage('does not support resumable HTTP range reads');

        (new HttpSourceReader)->metadata($sourceUrl, 30);
    }

    public function test_classifies_forbidden_and_missing_sources_as_permanent(): void
    {
        foreach ([403, 404] as $status) {
            $sourceUrl = "https://source.example/unavailable-{$status}.mp4";
            Http::preventStrayRequests();
            Http::fake([$sourceUrl => Http::response('unavailable', $status)]);
            $job = (new IngestSource('11111111-1111-4111-8111-111111111111'))
                ->withFakeQueueInteractions();

            try {
                $job->middleware()[0]->handle(
                    $job,
                    fn () => (new HttpSourceReader)->metadata($sourceUrl, 30),
                );
                self::fail("Expected HTTP {$status} to be permanent.");
            } catch (PermanentIngestException $exception) {
                self::assertSame("The source returned permanent HTTP status {$status}.", $exception->getMessage());
            }

            $job->assertFailedWith(PermanentIngestException::class);
        }
    }
}
