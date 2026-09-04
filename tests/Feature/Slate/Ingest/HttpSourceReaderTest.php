<?php

namespace Tests\Feature\Slate\Ingest;

use App\Slate\Ingest\HttpSourceReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support resumable HTTP range reads');

        (new HttpSourceReader)->metadata($sourceUrl, 30);
    }
}
