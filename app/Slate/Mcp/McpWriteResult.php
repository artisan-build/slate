<?php

declare(strict_types=1);

namespace App\Slate\Mcp;

final readonly class McpWriteResult
{
    /** @param array<string, mixed> $response */
    public function __construct(
        public string $outcome,
        public array $response = [],
        public ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            ...$this->response,
            ...$this->reason === null ? [] : ['reason' => $this->reason],
        ];
    }
}
