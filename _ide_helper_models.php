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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Run $run
 * @method static \Database\Factories\IngestFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereExpectedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereMultipartUploadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest wherePartSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereRetryUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereSourceUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereSourceValidator($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereSourceValidatorHeader($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ingest whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperIngest {}
}

namespace App\Models{
/**
 * @property string $id
 * @property RunStatus $status
 * @property RunTrust $trust
 * @property InputRejectionReason|null $rejection_reason
 * @property-read RunRecipe $recipe
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Database\Factories\RunFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereRecipe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereTrust($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Run whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperRun {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}

