<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Slate\Http\ResolvedDestination;

interface SourceReader
{
    public function metadata(ResolvedDestination $source, int $timeoutSeconds): SourceMetadata;

    public function read(
        ResolvedDestination $source,
        SourceMetadata $metadata,
        int $startByte,
        int $endByte,
        int $timeoutSeconds,
    ): SourcePart;
}
