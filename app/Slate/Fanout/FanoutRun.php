<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Storage\ObjectKey;
use InvalidArgumentException;

final readonly class FanoutRun
{
    /**
     * @param  non-empty-list<StoredVideo>  $inputs
     * @param  non-empty-list<BucketOperation>  $operations
     */
    public function __construct(
        public FanoutRunId $id,
        public array $inputs,
        public array $operations,
    ) {
        if ($inputs === [] || ! array_is_list($inputs)) {
            throw new InvalidArgumentException('Fan-out run inputs must be a non-empty list.');
        }

        foreach ($inputs as $index => $input) {
            if (! $input instanceof StoredVideo) {
                throw new InvalidArgumentException('Fan-out run inputs must be stored video descriptors.');
            }

            if ($input->objectKey->value() !== $this->inputKey($index)->value()) {
                throw new InvalidArgumentException('Fan-out input object keys must derive from the run ID and input index.');
            }
        }

        if ($operations === [] || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Fan-out run operations must be a non-empty list.');
        }

        foreach ($operations as $index => $operation) {
            if (! $operation instanceof BucketOperation) {
                throw new InvalidArgumentException('Fan-out run operations must use typed bucket operations.');
            }

            if ($operation->index() !== $index) {
                throw new InvalidArgumentException('Fan-out operations must use contiguous indexes in splice order.');
            }

            if ($operation->output()->objectKey->value() !== $this->operationKey($index)->value()) {
                throw new InvalidArgumentException('Fan-out output object keys must derive from the run ID and operation index.');
            }

            $firstOutput = $operations[0]->output();
            $output = $operation->output();

            if ($output->width !== $firstOutput->width
                || $output->height !== $firstOutput->height
                || $output->framesPerSecond !== $firstOutput->framesPerSecond
                || $output->qualityTier !== $firstOutput->qualityTier) {
                throw new InvalidArgumentException('Fan-out operation outputs must satisfy one copy-concat media contract.');
            }
        }
    }

    public function inputKey(int $index): ObjectKey
    {
        return $this->key(sprintf('work/inputs/%04d.mp4', $index));
    }

    public function operationKey(int $index): ObjectKey
    {
        return $this->key(sprintf('work/operations/%04d.mp4', $index));
    }

    public function receiptKey(int $index): ObjectKey
    {
        return $this->key(sprintf('work/receipts/%04d.json', $index));
    }

    public function outputKey(): ObjectKey
    {
        return $this->key('output.mp4');
    }

    public function resultKey(): ObjectKey
    {
        return $this->key('result.json');
    }

    /** @return list<ObjectKey> */
    public function workObjectKeys(): array
    {
        $keys = array_map(static fn (StoredVideo $input): ObjectKey => $input->objectKey, $this->inputs);

        foreach ($this->operations as $operation) {
            $keys[] = $operation->output()->objectKey;
            $keys[] = $this->receiptKey($operation->index());
        }

        return $keys;
    }

    /** @return list<ObjectKey> */
    public function allObjectKeys(): array
    {
        return [...$this->workObjectKeys(), $this->outputKey(), $this->resultKey()];
    }

    private function key(string $suffix): ObjectKey
    {
        $prefix = $this->id->prefix();

        return ObjectKey::withinPrefix($prefix, $prefix.'/'.$suffix);
    }
}
