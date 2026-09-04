<?php

declare(strict_types=1);

namespace App\Slate\Execution;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class ExecutionPlan
{
    /** @var non-empty-list<PlannedOperation> */
    public array $operations;

    /** @param array<array-key, mixed> $operations */
    public function __construct(
        public ExecutorStrategy $strategy,
        array $operations,
        public QueueRuntimeSettings $runtimeSettings,
    ) {
        if ($operations === [] || ! array_is_list($operations)) {
            throw new InvalidArgumentException('An execution plan requires an ordered operation list.');
        }

        foreach ($operations as $operation) {
            if (! $operation instanceof PlannedOperation) {
                throw new InvalidArgumentException('Execution plans accept only typed planned operations.');
            }
        }

        $this->operations = $operations;
    }

    /** @return list<ObjectKey> */
    public function intermediateObjectKeys(): array
    {
        $keys = [];

        foreach ($this->operations as $operation) {
            if ($operation instanceof RenderSilentSubClip
                || $operation instanceof CopyConcatSubClips
                || $operation instanceof CoarseCutSource) {
                if ($operation instanceof CoarseCutSource) {
                    foreach ($operation->ranges as $range) {
                        $keys[$range->output->value()] = $range->output;
                    }

                    continue;
                }

                $keys[$operation->outputKey()->value()] = $operation->outputKey();
            }
        }

        return array_values($keys);
    }

    /** @return list<ObjectKey> */
    public function outputObjectKeys(): array
    {
        $keys = [];

        foreach ($this->operations as $operation) {
            if ($operation instanceof WholeOperation
                || $operation instanceof MuxOperationAudio
                || $operation instanceof PreciseVideoClip) {
                $keys[$operation->outputKey()->value()] = $operation->outputKey();
            }
        }

        return array_values($keys);
    }
}
