<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IngestCleanup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slate:prune-ingests')]
#[Description('Abort or delete storage left by failed and expired direct ingests.')]
final class PruneExpiredIngestsCommand extends Command
{
    public function handle(IngestCleanup $cleanup): int
    {
        $count = $cleanup->sweepAbandoned();
        $this->info("Pruned {$count} expired ingests.");

        return self::SUCCESS;
    }
}
