<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Slate\Http\ResolvedDestination;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\CachingStream;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpSourceReader implements SourceReader
{
    public function metadata(ResolvedDestination $source, int $timeoutSeconds): SourceMetadata
    {
        $response = $this->request($source, ['Range' => 'bytes=0-0'], $timeoutSeconds);

        try {
            $bytes = $this->assertPartialResponse($response, 0, 0);
            $etag = trim($response->header('ETag'));
            $lastModified = trim($response->header('Last-Modified'));

            if ($etag !== '' && ! str_starts_with($etag, 'W/')) {
                return new SourceMetadata($bytes, 'ETag', $etag, $this->contentType($response));
            }

            if ($lastModified !== '') {
                return new SourceMetadata($bytes, 'Last-Modified', $lastModified, $this->contentType($response));
            }

            throw new PermanentIngestException('The source does not expose a stable HTTP validator for resumable ingest.');
        } finally {
            $response->close();
        }
    }

    public function read(
        ResolvedDestination $source,
        SourceMetadata $metadata,
        int $startByte,
        int $endByte,
        int $timeoutSeconds,
    ): SourcePart {
        if ($startByte < 0 || $endByte < $startByte || $endByte >= $metadata->bytes) {
            throw new RuntimeException('The requested source byte range is invalid.');
        }

        $conditionHeader = $metadata->validatorHeader === 'ETag' ? 'If-Match' : 'If-Unmodified-Since';
        $response = $this->request($source, [
            'Range' => "bytes={$startByte}-{$endByte}",
            $conditionHeader => $metadata->validator,
        ], $timeoutSeconds);

        try {
            $bytes = $this->assertPartialResponse($response, $startByte, $endByte);

            if ($bytes !== $metadata->bytes) {
                throw new PermanentIngestException('The source size changed while reading a resumable range.');
            }

            return new SourcePart(
                new CachingStream($response->toPsrResponse()->getBody()),
                $endByte - $startByte + 1,
            );
        } catch (\Throwable $exception) {
            $response->close();

            throw $exception;
        }
    }

    /** @param array<string, string> $headers */
    private function request(ResolvedDestination $source, array $headers, int $timeoutSeconds): Response
    {
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('Source request timeout must be positive.');
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Source ingest requires cURL DNS pinning.');
        }

        $response = Http::withHeaders([...$headers, 'Accept-Encoding' => 'identity'])
            ->setHandler(new CurlHandler)
            ->withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => [$source->curlResolveEntry()]],
            ])
            ->timeout($timeoutSeconds)
            ->get($source->url);

        if (in_array($response->status(), [400, 401, 403, 404, 410, 416], true)) {
            $status = $response->status();
            $response->close();

            throw new PermanentIngestException("The source returned permanent HTTP status {$status}.");
        }

        return $response->throw();
    }

    private function contentType(Response $response): ?string
    {
        $contentType = trim(explode(';', $response->header('Content-Type'), 2)[0]);

        return $contentType === '' ? null : $contentType;
    }

    private function assertPartialResponse(Response $response, int $startByte, int $endByte): int
    {
        if ($response->status() !== 206) {
            throw new PermanentIngestException('The source does not support resumable HTTP range reads.');
        }

        $contentRange = $response->header('Content-Range');
        $pattern = sprintf('/^bytes %d-%d\/(\d+)$/', $startByte, $endByte);

        if (preg_match($pattern, $contentRange, $matches) !== 1 || (int) $matches[1] < 1) {
            throw new PermanentIngestException('The source returned an invalid Content-Range header.');
        }

        return (int) $matches[1];
    }
}
