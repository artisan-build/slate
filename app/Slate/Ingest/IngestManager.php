<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\Run;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\RunObjectKeys;
use DateTimeInterface;

final readonly class IngestManager
{
    public function __construct(private IngestStorage $storage) {}

    public function queueSource(
        Run $run,
        int $inputIndex,
        string $filename,
        string $sourceUrl,
    ): Ingest {
        $ingest = Ingest::expectSource(
            $run,
            RunObjectKeys::input($run->id, $inputIndex, $filename),
            $sourceUrl,
        );
        IngestSource::dispatch($ingest->id)->afterCommit();

        return $ingest;
    }

    public function presignedPut(
        Run $run,
        int $inputIndex,
        string $filename,
        DateTimeInterface $expiration,
    ): PresignedUpload {
        $objectKey = RunObjectKeys::input($run->id, $inputIndex, $filename);
        $ingest = Ingest::expectDirect($run, $objectKey);
        $upload = $this->storage->temporaryUploadUrl($objectKey, $expiration);

        return new PresignedUpload($ingest->id, $objectKey, $upload['url'], $upload['headers']);
    }
}
