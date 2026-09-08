<?php

namespace Database\Factories;

use App\Models\IngestMode;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Storage\SourceObjectKeys;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Source> */
final class SourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $id = Str::uuid()->toString();

        return [
            'id' => $id,
            'owner_subject_type' => SubjectType::Application,
            'owner_subject_ref' => 'test-subject',
            'object_key' => SourceObjectKeys::object($id)->value(),
            'mode' => IngestMode::Direct,
            'status' => SourceStatus::Pending,
            'bytes' => 1024,
            'content_type' => 'video/mp4',
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
