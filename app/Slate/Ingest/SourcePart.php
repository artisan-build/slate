<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final class SourcePart
{
    public function __construct(
        public readonly StreamInterface $stream,
        public readonly int $bytes,
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
