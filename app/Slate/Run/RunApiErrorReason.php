<?php

declare(strict_types=1);

namespace App\Slate\Run;

enum RunApiErrorReason: string
{
    case MalformedRecipe = 'malformed_recipe';
    case ResultNotReady = 'result_not_ready';
    case RunFailed = 'run_failed';
    case RunNotFound = 'run_not_found';
    case RunIdConflict = 'run_id_conflict';
    case InvalidWebhookDestination = 'invalid_webhook_destination';
    case SourceNotFound = 'source_not_found';
    case SourceNotReady = 'source_not_ready';
}
