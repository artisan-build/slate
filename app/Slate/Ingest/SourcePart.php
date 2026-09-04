<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final readonly class SourcePart
{
    public function __construct(
        public StreamInterface $stream,
        public int $bytes,
    ) {
        if ($bytes < 1 || ! $stream->isReadable()) {
            throw new InvalidArgumentException('A source part requires a readable non-empty stream.');
        }
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
