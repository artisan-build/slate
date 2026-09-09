<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;

final readonly class RunCleanup
{
    public function __construct(
        private ObjectStorage $objectStorage,
        private FanoutStorageGuard $storageGuard,
    ) {}

    public function workObjects(Run $run): void
    {
        $this->storageGuard->assertSafe();
        $outputKeys = [RunObjectKeys::deliveryOutput($run->id)->value() => true];

        foreach (array_keys($run->recipe()->operations) as $operationIndex) {
            $outputKey = RunObjectKeys::operationOutput($run->id, $operationIndex);
            $outputKeys[$outputKey->value()] = true;
        }

        $this->deleteExisting(array_values(array_filter(
            $this->objectStorage->keysWithinPrefix(RunObjectKeys::prefix($run->id)),
            static fn (ObjectKey $key): bool => ! isset($outputKeys[$key->value()]),
        )));
    }

    public function allObjects(Run $run): void
    {
        $this->storageGuard->assertSafe();
        $this->deleteExisting($this->objectStorage->keysWithinPrefix(RunObjectKeys::prefix($run->id)));
    }

    /** @param list<ObjectKey> $objectKeys */
    private function deleteExisting(array $objectKeys): void
    {
        foreach ($objectKeys as $objectKey) {
            if ($this->objectStorage->exists($objectKey)) {
                $this->objectStorage->delete($objectKey);
            }
        }
    }
}
