<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class PresignedUpload
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $ingestId,
        public ObjectKey $objectKey,
        public string $url,
        public array $headers,
    ) {
        if ($ingestId === '' || $url === '') {
            throw new InvalidArgumentException('A presigned upload requires an ingest ID and URL.');
        }
    }
}
