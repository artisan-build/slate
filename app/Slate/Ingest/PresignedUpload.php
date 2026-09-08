<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class PresignedUpload
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $id,
        public ObjectKey $objectKey,
        public string $url,
        public array $headers,
    ) {
        if ($id === '' || $url === '') {
            throw new InvalidArgumentException('A presigned upload requires an ID and URL.');
        }
    }
}
