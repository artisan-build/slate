<?php

declare(strict_types=1);

namespace App\Slate\Storage;

/**
 * Stages objects between durable bucket storage and one job's local scratch directory.
 *
 * This seam provides bucket transfer for jobs that may run on different hosts. It does not enforce
 * that every queued job uses it; the legacy FfmpegBenchmark still passes local chunk paths pending PR 7.
 */
interface ObjectStorage
{
    public function stageIn(ObjectKey $objectKey, ScratchPath $localPath): void;

    public function stageOut(ScratchPath $localPath, ObjectKey $objectKey): void;
}
