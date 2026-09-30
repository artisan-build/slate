<?php

declare(strict_types=1);

namespace App\Slate\Ingest;

use App\Jobs\FinalizeSource;
use App\Jobs\IngestRemoteSource;
use App\Jobs\IngestSource;
use App\Models\Ingest;
use App\Models\Run;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\RunObjectKeys;
use ArtisanBuild\BuiltForCloud\Subject;
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
        dispatch(new IngestSource($ingest->id))->afterCommit();

        return $ingest;
    }

    public function presignedPut(
        Run $run,
        int $inputIndex,
        string $filename,
        DateTimeInterface $expiration,
    ): PresignedUpload {
        $objectKey = RunObjectKeys::input($run->id, $inputIndex, $filename);
        $ingest = Ingest::expectDirect($run, $objectKey, $expiration);
        $upload = $this->storage->temporaryUploadUrl($objectKey, $expiration);

        return new PresignedUpload($ingest->id, $objectKey, $upload['url'], $upload['headers']);
    }

    public function queueRemoteSource(Subject $owner, string $sourceUrl, ?string $sourceId = null): Source
    {
        $source = Source::expectRemote($owner, $sourceUrl, $sourceId);
        dispatch(new IngestRemoteSource($source->id))->afterCommit();

        return $source;
    }

    public function presignedSource(
        Subject $owner,
        string $contentType,
        int $bytes,
        DateTimeInterface $expiration,
    ): PresignedUpload {
        $source = Source::expectDirect($owner, $contentType, $bytes, $expiration);
        $upload = $this->storage->temporaryUploadUrl($source->objectKey(), $expiration, singleUse: true);

        return new PresignedUpload($source->id, $source->objectKey(), $upload['url'], $upload['headers']);
    }

    public function dispatchFinalizeIfUploaded(Source $source): bool
    {
        if ($source->status !== SourceStatus::Pending
            || ($source->expires_at !== null && $source->expires_at->isPast())
            || ! $this->storage->exists($source->objectKey())
            || ! $source->claimForFinalization()) {
            return false;
        }

        dispatch(new FinalizeSource($source->id))->afterCommit();

        return true;
    }
}
