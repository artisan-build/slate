<?php

declare(strict_types=1);

namespace App\Services;

use App\Slate\Fanout\FanoutRun;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;

final readonly class FanoutRunCleanup
{
    public function __construct(private ObjectStorage $objectStorage) {}

    public function workObjects(FanoutRun $run): void
    {
        $this->deleteExisting($run->workObjectKeys());
    }

    public function allObjects(FanoutRun $run): void
    {
        $this->deleteExisting($run->allObjectKeys());
    }

    /** @param  list<ObjectKey>  $objectKeys */
    private function deleteExisting(array $objectKeys): void
    {
        foreach ($objectKeys as $objectKey) {
            if ($this->objectStorage->exists($objectKey)) {
                $this->objectStorage->delete($objectKey);
            }
        }
    }
}
