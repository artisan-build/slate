<?php

declare(strict_types=1);

namespace App\Slate\Fanout;

use App\Slate\Recipe\OperationName;
use InvalidArgumentException;
use JsonException;

final readonly class FanoutReceipt
{
    public function __construct(
        public int $operationIndex,
        public OperationName $operation,
        public string $hostname,
        public int $processingDurationMs,
        public int $outputSizeBytes,
    ) {
        if ($operationIndex < 0 || $hostname === '' || $processingDurationMs < 0 || $outputSizeBytes < 1) {
            throw new InvalidArgumentException('Fan-out receipt contains invalid operation evidence.');
        }
    }

    public static function capture(BucketOperation $operation, int $processingDurationMs, int $outputSizeBytes): self
    {
        return new self(
            $operation->index(),
            $operation->name(),
            gethostname() ?: 'unknown',
            $processingDurationMs,
            $outputSizeBytes,
        );
    }

    /** @throws JsonException */
    public static function fromJson(string $json, BucketOperation $expected): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Fan-out receipt must decode to an object.');
        }

        $operation = OperationName::tryFrom(is_string($data['operation'] ?? null) ? $data['operation'] : '');
        $receipt = new self(
            is_int($data['operation_index'] ?? null) ? $data['operation_index'] : -1,
            $operation ?? throw new InvalidArgumentException('Fan-out receipt contains an unknown operation.'),
            is_string($data['hostname'] ?? null) ? $data['hostname'] : '',
            is_int($data['processing_duration_ms'] ?? null) ? $data['processing_duration_ms'] : -1,
            is_int($data['output_size_bytes'] ?? null) ? $data['output_size_bytes'] : 0,
        );

        if ($receipt->operationIndex !== $expected->index() || $receipt->operation !== $expected->name()) {
            throw new InvalidArgumentException('Fan-out receipt does not match its expected operation.');
        }

        return $receipt;
    }

    /** @return array{operation_index: int, operation: string, hostname: string, processing_duration_ms: int, output_size_bytes: int} */
    public function toArray(): array
    {
        return [
            'operation_index' => $this->operationIndex,
            'operation' => $this->operation->value,
            'hostname' => $this->hostname,
            'processing_duration_ms' => $this->processingDurationMs,
            'output_size_bytes' => $this->outputSizeBytes,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
