<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_subject_type
 * @property string $owner_subject_ref
 * @property string $tool
 * @property string $idempotency_key
 * @property string $intent_hash
 * @property string $target_type
 * @property string $target_id
 * @property string $status
 * @property string|null $execution_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property array<string, mixed>|null $response
 */
#[Fillable([])]
final class McpIdempotencyClaim extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'immutable_datetime',
            'response' => 'array',
        ];
    }
}
