<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use DateTimeInterface;

/**
 * Stages objects between durable bucket storage and one job's local scratch directory.
 *
 * This seam provides bucket transfer for jobs that may run on different hosts. It does not enforce
 * that every queued job uses it.
 */
interface ObjectStorage
{
    public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void;

    public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void;

    public function exists(ObjectKey $objectKey): bool;

    public function delete(ObjectKey $objectKey): void;

    public function temporaryUrl(ObjectKey $objectKey, DateTimeInterface $expiration): string;

    /** @return list<ObjectKey> */
    public function keysWithinPrefix(string $prefix): array;
}
