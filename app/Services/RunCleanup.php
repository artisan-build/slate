<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
use App\Slate\Execution\ExecutionPlan;
use App\Slate\Execution\RunExecutor;
use App\Slate\Run\RunStillClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;

final readonly class RunCleanup
{
    public function __construct(
        private ObjectStorage $objectStorage,
        private FanoutStorageGuard $storageGuard,
        private RunExecutor $executor,
    ) {}

    public function workObjects(Run $run): void
    {
        $plan = $this->executor->plan($run);
        $this->deleteExisting($this->workObjectKeys($run, $plan));
    }

    public function allObjects(Run $run): void
    {
        $plan = $this->executor->plan($run);
        $this->deleteExisting([...$this->workObjectKeys($run, $plan), ...$plan->outputObjectKeys()]);
    }

    /** @return list<ObjectKey> */
    private function workObjectKeys(Run $run, ExecutionPlan $plan): array
    {
        $keys = $plan->intermediateObjectKeys();

        foreach ($run->recipe->operations as $operation) {
            if ($operation instanceof RunStillClip) {
                $keys[] = $operation->image;
                $keys[] = $operation->audio;
            }
        }

        return $keys;
    }

    /** @param list<ObjectKey> $objectKeys */
    private function deleteExisting(array $objectKeys): void
    {
        $this->storageGuard->assertSafe();
        $uniqueKeys = [];

        foreach ($objectKeys as $objectKey) {
            $uniqueKeys[$objectKey->value()] = $objectKey;
        }

        foreach ($uniqueKeys as $objectKey) {
            if ($this->objectStorage->exists($objectKey)) {
                $this->objectStorage->delete($objectKey);
            }
        }
    }
}
