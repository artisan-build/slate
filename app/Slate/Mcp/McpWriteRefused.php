<?php

declare(strict_types=1);

namespace App\Slate\Mcp;

use RuntimeException;

final class McpWriteRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The MCP write was refused.');
    }
}
