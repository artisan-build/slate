<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Ingest;
use App\Models\Run;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PruneExpiredIngestsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prunes_expired_ingests_and_is_registered_hourly(): void
    {
        $run = Run::factory()->create();
        $ingest = Ingest::expectDirect(
            $run,
            RunObjectKeys::input($run->id, 0, 'abandoned.bin'),
            now()->subMinute(),
        );
        $storage = $this->mock(IngestStorage::class);
        $storage->shouldReceive('exists')
            ->once()
            ->withArgs(fn (ObjectKey $key): bool => $key->value() === $ingest->object_key)
            ->andReturnFalse();

        $this->artisan('slate:prune-ingests')
            ->expectsOutput('Pruned 1 expired ingests.')
            ->assertSuccessful();

        $this->artisan('schedule:list')
            ->expectsOutputToContain('slate:prune-ingests')
            ->assertSuccessful();
    }
}
