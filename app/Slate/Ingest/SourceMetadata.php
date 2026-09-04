<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use InvalidArgumentException;

final readonly class SourceMetadata
{
    public function __construct(
        public int $bytes,
        public string $validatorHeader,
        public string $validator,
    ) {
        if ($bytes < 1
            || ! in_array($validatorHeader, ['ETag', 'Last-Modified'], true)
            || $validator === '') {
            throw new InvalidArgumentException('A resumable source requires a positive size and stable HTTP validator.');
        }
    }
}
