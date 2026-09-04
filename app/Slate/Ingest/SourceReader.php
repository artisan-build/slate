<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

interface SourceReader
{
    public function metadata(string $sourceUrl, int $timeoutSeconds): SourceMetadata;

    public function read(
        string $sourceUrl,
        SourceMetadata $metadata,
        int $startByte,
        int $endByte,
        int $timeoutSeconds,
    ): SourcePart;
}
