<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property string $id
 * @property string $run_id
 * @property string $object_key
 * @property IngestMode $mode
 * @property IngestStatus $status
 * @property string|null $source_url
 * @property int|null $expected_bytes
 * @property string|null $source_validator_header
 * @property string|null $source_validator
 * @property int|null $part_size_bytes
 * @property string|null $multipart_upload_id
 * @property CarbonImmutable|null $retry_until
 * @property CarbonImmutable|null $expires_at
 * @property-read \App\Models\Run|null $run
 * @method static \Database\Factories\IngestFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperIngest {}
}

namespace App\Models{
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|McpIdempotencyClaim newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|McpIdempotencyClaim newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|McpIdempotencyClaim query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperMcpIdempotencyClaim {}
}

namespace App\Models{
/**
 * @property string $id
 * @property RunStatus $status
 * @property RunTrust $trust
 * @property InputRejectionReason|null $rejection_reason
 * @property SubjectType $owner_subject_type
 * @property string $owner_subject_ref
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property-read RunRecipe $recipe
 * @method static \Database\Factories\RunFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperRun {}
}

namespace App\Models{
/**
 * @property string $id
 * @property SubjectType $owner_subject_type
 * @property string $owner_subject_ref
 * @property string $object_key
 * @property IngestMode $mode
 * @property SourceStatus $status
 * @property InputRejectionReason|null $rejection_reason
 * @property string|null $source_url
 * @property int $bytes
 * @property string|null $content_type
 * @property string|null $source_validator_header
 * @property string|null $source_validator
 * @property int|null $part_size_bytes
 * @property string|null $multipart_upload_id
 * @property CarbonImmutable|null $retry_until
 * @property CarbonImmutable|null $expires_at
 * @method static \Database\Factories\SourceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Source newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Source newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Source query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperSource {}
}

