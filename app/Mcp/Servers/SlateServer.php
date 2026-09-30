<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateRemoteSourceTool;
use App\Mcp\Tools\CreateRunTool;
use App\Mcp\Tools\RunResultUrlTool;
use App\Mcp\Tools\RunStatusTool;
use App\Mcp\Tools\SourceStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

final class SlateServer extends Server
{
    protected string $name = 'Slate';

    protected string $version = '1.0.0';

    protected string $instructions = 'Inspect owned Slate sources and runs, retrieve completed results, and submit typed idempotent work.';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        SourceStatusTool::class,
        RunStatusTool::class,
        RunResultUrlTool::class,
        CreateRemoteSourceTool::class,
        CreateRunTool::class,
    ];
}
