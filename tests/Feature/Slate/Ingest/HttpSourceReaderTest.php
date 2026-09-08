<?php

namespace Tests\Feature\Slate\Ingest;

use App\Jobs\IngestSource;
use App\Slate\Http\ResolvedDestination;
use App\Slate\Ingest\HttpSourceReader;
use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\SourceMetadata;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

final class HttpSourceReaderTest extends TestCase
{
    public function test_reads_validated_ranges_without_materializing_the_whole_source(): void
    {
        /** @var list<array<string, mixed>> $capturedOptions */
        $capturedOptions = [];
        $sourceUrl = 'https://source.example/video.mp4';
        Http::preventStrayRequests();
        Http::globalMiddleware(
            static function (callable $handler) use (&$capturedOptions): Closure {
                return static function (RequestInterface $request, array $options) use ($handler, &$capturedOptions): PromiseInterface {
                    $capturedOptions[] = $options;

                    return $handler($request, $options);
                };
            },
        );
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
        $source = new ResolvedDestination($sourceUrl, 'source.example', '93.184.216.34');

        $metadata = $reader->metadata($source, 30);
        $part = $reader->read($source, $metadata, 6, 11, 30);
        $body = $part->stream->getContents();
        $part->close();

        self::assertSame(12, $metadata->bytes);
        self::assertSame('ETag', $metadata->validatorHeader);
        self::assertSame('"stable-source"', $metadata->validator);
        self::assertSame('source', $body);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Range', 'bytes=6-11')
            && $request->hasHeader('If-Match', '"stable-source"'));
        self::assertCount(2, $capturedOptions);

        foreach ($capturedOptions as $options) {
            self::assertFalse($options['allow_redirects'] ?? null);
            self::assertSame('', $options['proxy'] ?? null);
            self::assertSame(
                ['source.example:443:93.184.216.34'],
                $options['curl'][CURLOPT_RESOLVE] ?? null,
            );
        }
    }

    public function test_rejects_a_source_that_ignores_range_requests(): void
    {
        $sourceUrl = 'https://source.example/no-ranges.mp4';
        Http::preventStrayRequests();
        Http::fake([$sourceUrl => Http::response('whole source', 200)]);

        $this->expectException(PermanentIngestException::class);
        $this->expectExceptionMessage('does not support resumable HTTP range reads');

        (new HttpSourceReader)->metadata(
            new ResolvedDestination($sourceUrl, 'source.example', '93.184.216.34'),
            30,
        );
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
                    fn () => (new HttpSourceReader)->metadata(
                        new ResolvedDestination($sourceUrl, 'source.example', '93.184.216.34'),
                        30,
                    ),
                );
                self::fail("Expected HTTP {$status} to be permanent.");
            } catch (PermanentIngestException $exception) {
                self::assertSame("The source returned permanent HTTP status {$status}.", $exception->getMessage());
            }

            $job->assertFailedWith(PermanentIngestException::class);
        }
    }

    public function test_range_redirect_cannot_connect_to_private_location_and_uses_guard_checked_address(): void
    {
        /** @var array<string, mixed>|null $capturedOptions */
        $capturedOptions = null;
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $counter = tempnam(sys_get_temp_dir(), 'slate-source-reader-');
        self::assertIsString($counter);
        file_put_contents($counter, '');
        $sourceUrl = "http://source.example.test:{$port}/range";
        $destination = new ResolvedDestination($sourceUrl, 'source.example.test', '127.0.0.1', $port);
        $server = Process::timeout(10)
            ->env([
                'SLATE_REDIRECT_COUNTER' => $counter,
                'SLATE_REDIRECT_PRIVATE_URL' => "http://127.0.0.1:{$port}/private",
            ])
            ->start([
                PHP_BINARY,
                '-S',
                "127.0.0.1:{$port}",
                base_path('tests/Fixtures/HttpSource/redirect-router.php'),
            ]);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $serverErrorCode, $serverErrorMessage, 0.1);

            if (is_resource($socket)) {
                fclose($socket);
                break;
            }

            Sleep::usleep(25_000);
        }

        self::assertTrue($server->running(), $server->errorOutput());
        Http::globalMiddleware(
            static function (callable $handler) use (&$capturedOptions): Closure {
                return static function (RequestInterface $request, array $options) use ($handler, &$capturedOptions): PromiseInterface {
                    $capturedOptions = $options;

                    return $handler($request, $options);
                };
            },
        );

        $connectionMarkers = '';

        try {
            try {
                (new HttpSourceReader)->read(
                    $destination,
                    new SourceMetadata(12, 'ETag', '"stable-source"'),
                    6,
                    11,
                    3,
                );
                self::fail('Expected a redirected range response to be rejected.');
            } catch (PermanentIngestException $exception) {
                self::assertSame('The source does not support resumable HTTP range reads.', $exception->getMessage());
            }
        } finally {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $connectionMarkers = file_get_contents($counter);

                if ($connectionMarkers !== '') {
                    break;
                }

                Sleep::usleep(25_000);
            }

            $server->stop();
        }

        self::assertNotNull($capturedOptions);
        self::assertFalse($capturedOptions['allow_redirects'] ?? null);
        self::assertSame('', $capturedOptions['proxy'] ?? null);
        self::assertSame(
            ["source.example.test:{$port}:127.0.0.1"],
            $capturedOptions['curl'][CURLOPT_RESOLVE] ?? null,
        );
        self::assertSame("request\n", $connectionMarkers);
        unlink($counter);
    }
}
