<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$basePath = $argv[1] ?? null;
$environmentPath = $argv[2] ?? null;

if (! is_string($basePath) || ! is_string($environmentPath)) {
    fwrite(STDERR, 'Base and environment paths are required.');

    exit(1);
}

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->useEnvironmentPath($environmentPath);
$kernel = $app->make(Kernel::class);

$upRequest = Request::create('/up', 'GET');
$upResponse = $kernel->handle($upRequest);
$kernel->terminate($upRequest, $upResponse);
$sourceRequest = Request::create(
    '/api/v1/sources',
    'POST',
    content: json_encode(['upload' => ['content_type' => 'video/mp4', 'size' => 10]], JSON_THROW_ON_ERROR),
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
);
$sourceResponse = $kernel->handle($sourceRequest);
$kernel->terminate($sourceRequest, $sourceResponse);

fwrite(STDOUT, json_encode([
    'session_driver_in_process' => getenv('SESSION_DRIVER') !== false,
    'session_driver' => config('session.driver'),
    'up_status' => $upResponse->getStatusCode(),
    'source_status' => $sourceResponse->getStatusCode(),
], JSON_THROW_ON_ERROR));
