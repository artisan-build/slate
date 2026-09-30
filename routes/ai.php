<?php

declare(strict_types=1);

use App\Mcp\Servers\SlateServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', SlateServer::class)
    ->middleware('bfc.mcp:product,read');

Mcp::web('/mcp-write', SlateServer::class)
    ->middleware('bfc.mcp:product,write');
