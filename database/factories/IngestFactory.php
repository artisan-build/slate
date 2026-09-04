<?php

namespace Database\Factories;

use App\Models\Ingest;
use App\Models\IngestMode;
use App\Models\IngestStatus;
use App\Models\Run;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingest>
 */
class IngestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'object_key' => fn (array $attributes): string => RunObjectKeys::input(
                (string) $attributes['run_id'],
                0,
                'source.mp4',
            )->value(),
            'mode' => IngestMode::Source,
            'status' => IngestStatus::Expected,
            'source_url' => fake()->url(),
            'retry_until' => now()->addSeconds((int) config('slate.ingest.retry_deadline_seconds')),
        ];
    }
}
