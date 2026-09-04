<?php

namespace App\Slate\Execution;

enum VisibilityWindowSource: string
{
    case NamedQueueRetryAfter = 'named_queue_retry_after';
    case ConnectionRetryAfter = 'connection_retry_after';
    case ConfiguredFallback = 'configured_fallback';
}
