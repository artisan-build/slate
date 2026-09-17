<?php

namespace App\Models;

use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Carbon\CarbonImmutable;
use Database\Factories\IngestFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

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
 *
 * @mixin IdeHelperIngest
 */
#[Fillable([])]
final class Ingest extends Model
{
    /** @use HasFactory<IngestFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $hidden = ['source_url', 'source_validator', 'multipart_upload_id'];

    public static function expectSource(Run $run, ObjectKey $objectKey, string $sourceUrl): self
    {
        if ($sourceUrl === '') {
            throw new InvalidArgumentException('A source ingest requires a URL.');
        }

        $retryDeadlineSeconds = config('slate.ingest.retry_deadline_seconds');

        if (! is_int($retryDeadlineSeconds) || $retryDeadlineSeconds < 1) {
            throw new InvalidArgumentException('Source ingest retry deadline must be a positive integer.');
        }

        return self::expect(
            $run,
            $objectKey,
            IngestMode::Source,
            $sourceUrl,
            now()->addSeconds($retryDeadlineSeconds),
            null,
        );
    }

    public static function expectDirect(
        Run $run,
        ObjectKey $objectKey,
        DateTimeInterface $expiration,
    ): self {
        return self::expect($run, $objectKey, IngestMode::Direct, null, null, $expiration);
    }

    public function objectKey(): ObjectKey
    {
        return ObjectKey::withinPrefix(RunObjectKeys::prefix($this->run_id), $this->object_key);
    }

    public function sourceUrl(): string
    {
        if ($this->mode !== IngestMode::Source || ! is_string($this->source_url) || $this->source_url === '') {
            throw new LogicException('Only source ingests have a readable source URL.');
        }

        return $this->source_url;
    }

    public function beginUpload(SourceMetadata $metadata, int $partSizeBytes, string $uploadId): void
    {
        if ($this->mode !== IngestMode::Source || $partSizeBytes < 1 || $uploadId === '') {
            throw new LogicException('Only source ingests can begin a multipart upload.');
        }

        if ($this->status->isTerminal()) {
            throw new LogicException('A terminal ingest cannot begin a multipart upload.');
        }

        $this->assertSourceMetadata($metadata);

        $this->expected_bytes = $metadata->bytes;
        $this->source_validator_header = $metadata->validatorHeader;
        $this->source_validator = $metadata->validator;
        $this->part_size_bytes ??= $partSizeBytes;
        $this->multipart_upload_id ??= $uploadId;
        $this->status = IngestStatus::Uploading;
        $this->save();
    }

    public function assertSourceMetadata(SourceMetadata $metadata): void
    {
        if ($this->expected_bytes !== null && $this->expected_bytes !== $metadata->bytes) {
            throw new PermanentIngestException('The source size changed while resuming ingest.');
        }

        if ($this->source_validator_header !== null
            && ($this->source_validator_header !== $metadata->validatorHeader
                || $this->source_validator !== $metadata->validator)) {
            throw new PermanentIngestException('The source changed while resuming ingest.');
        }
    }

    public function markSucceeded(): bool
    {
        return $this->transitionTo(IngestStatus::Succeeded);
    }

    public function markFailed(): bool
    {
        return $this->transitionTo(IngestStatus::Failed);
    }

    public function transitionTo(IngestStatus $nextStatus): bool
    {
        $changed = DB::transaction(function () use ($nextStatus): bool {
            $ingest = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($ingest->status === $nextStatus || $ingest->status->isTerminal()) {
                return false;
            }

            if (($ingest->status === IngestStatus::Expected
                && ! in_array($nextStatus, [IngestStatus::Uploading, IngestStatus::Succeeded, IngestStatus::Failed], true))
                || ($ingest->status === IngestStatus::Uploading
                    && ! in_array($nextStatus, [IngestStatus::Succeeded, IngestStatus::Failed], true))) {
                throw new LogicException("Ingest cannot transition from {$ingest->status->value} to {$nextStatus->value}.");
            }

            $ingest->status = $nextStatus;

            if ($nextStatus === IngestStatus::Succeeded) {
                $ingest->source_url = null;
            }

            return $ingest->save();
        });

        $this->refresh();

        return $changed;
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => IngestMode::class,
            'status' => IngestStatus::class,
            'source_url' => 'encrypted',
            'source_validator' => 'encrypted',
            'multipart_upload_id' => 'encrypted',
            'retry_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    private static function expect(
        Run $run,
        ObjectKey $objectKey,
        IngestMode $mode,
        ?string $sourceUrl,
        ?DateTimeInterface $retryUntil,
        ?DateTimeInterface $expiresAt,
    ): self {
        if (! $run->exists || $objectKey->value() !== ObjectKey::withinPrefix(
            RunObjectKeys::prefix($run->id),
            $objectKey->value(),
        )->value()) {
            throw new InvalidArgumentException('An ingest requires a persisted owning run.');
        }

        $ingest = new self;
        $ingest->run_id = $run->id;
        $ingest->object_key = $objectKey->value();
        $ingest->mode = $mode;
        $ingest->status = IngestStatus::Expected;
        $ingest->source_url = $sourceUrl;
        $ingest->retry_until = $retryUntil === null ? null : CarbonImmutable::instance($retryUntil);
        $ingest->expires_at = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);
        $ingest->save();

        return $ingest;
    }
}
