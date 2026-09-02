<?php

declare(strict_types=1);

namespace App\Slate\Storage;

/**
 * Stages objects between durable bucket storage and one job's local scratch directory.
 *
 * Queued jobs may run on different hosts. Stage-out followed by stage-in is therefore the transfer
 * mechanism between jobs; a local scratch path must never be passed to another queued job.
 */
interface ObjectStorage
{
    public function stageIn(ObjectKey $objectKey, string $localPath): void;

    public function stageOut(string $localPath, ObjectKey $objectKey): void;
}
