<?php

declare(strict_types=1);

namespace App\Models;

use App\Slate\Ingest\PermanentIngestException;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\SourceObjectKeys;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Carbon\CarbonImmutable;
use Database\Factories\SourceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

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
 *
 * @mixin IdeHelperSource
 */
#[Fillable([])]
final class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $hidden = ['source_url', 'source_validator', 'multipart_upload_id'];

    public static function expectRemote(Subject $owner, string $sourceUrl): self
    {
        if ($sourceUrl === '') {
            throw new InvalidArgumentException('A remote source requires a URL.');
        }

        $retryDeadlineSeconds = config('slate.ingest.retry_deadline_seconds');

        if (! is_int($retryDeadlineSeconds) || $retryDeadlineSeconds < 1) {
            throw new InvalidArgumentException('Source ingest retry deadline must be a positive integer.');
        }

        return self::expect(
            $owner,
            IngestMode::Source,
            SourceStatus::Ingesting,
            $sourceUrl,
            null,
            null,
            now()->addSeconds($retryDeadlineSeconds),
            null,
        );
    }

    public static function expectDirect(
        Subject $owner,
        string $contentType,
        int $bytes,
        DateTimeInterface $expiration,
    ): self {
        if ($contentType === '' || $bytes < 1) {
            throw new InvalidArgumentException('A direct source requires a content type and positive size.');
        }

        return self::expect(
            $owner,
            IngestMode::Direct,
            SourceStatus::Pending,
            null,
            $bytes,
            $contentType,
            null,
            $expiration,
        );
    }

    /** @return Builder<self> */
    public static function ownedBy(Subject $subject): Builder
    {
        return self::query()
            ->where('owner_subject_type', $subject->type->value)
            ->where('owner_subject_ref', $subject->ref);
    }

    public function objectKey(): ObjectKey
    {
        return ObjectKey::withinPrefix(SourceObjectKeys::prefix($this->id), $this->object_key);
    }

    public function sourceUrl(): string
    {
        if ($this->mode !== IngestMode::Source || ! is_string($this->source_url) || $this->source_url === '') {
            throw new LogicException('Only remote sources have a readable source URL.');
        }

        return $this->source_url;
    }

    public function beginUpload(SourceMetadata $metadata, int $partSizeBytes, string $uploadId): void
    {
        if ($this->mode !== IngestMode::Source || $partSizeBytes < 1 || $uploadId === '') {
            throw new LogicException('Only remote sources can begin a multipart upload.');
        }

        if ($this->status->isTerminal()) {
            throw new LogicException('A terminal source cannot begin a multipart upload.');
        }

        $this->assertSourceMetadata($metadata);
        $this->bytes = $metadata->bytes;
        $this->content_type = $metadata->contentType;
        $this->source_validator_header = $metadata->validatorHeader;
        $this->source_validator = $metadata->validator;
        $this->part_size_bytes ??= $partSizeBytes;
        $this->multipart_upload_id ??= $uploadId;
        $this->save();
    }

    public function assertSourceMetadata(SourceMetadata $metadata): void
    {
        if ($this->bytes > 0 && $this->bytes !== $metadata->bytes) {
            throw new PermanentIngestException('The source size changed while resuming ingest.');
        }

        if ($this->source_validator_header !== null
            && ($this->source_validator_header !== $metadata->validatorHeader
                || $this->source_validator !== $metadata->validator)) {
            throw new PermanentIngestException('The source changed while resuming ingest.');
        }
    }

    public function assertStoredBytes(int $bytes): void
    {
        if ($bytes !== $this->bytes) {
            throw new PermanentIngestException('The uploaded source size does not match its declared size.');
        }
    }

    public function claimForFinalization(): bool
    {
        $changed = DB::transaction(function (): bool {
            $source = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($source->status !== SourceStatus::Pending) {
                return false;
            }

            $source->status = SourceStatus::Ingesting;

            return $source->save();
        });

        $this->refresh();

        return $changed;
    }

    public function markReady(): bool
    {
        return $this->transitionTo(SourceStatus::Ready);
    }

    public function markFailed(): bool
    {
        return $this->transitionTo(SourceStatus::Failed);
    }

    public function reject(InputRejectionReason $reason): bool
    {
        $changed = DB::transaction(function () use ($reason): bool {
            $source = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($source->status->isTerminal()) {
                return false;
            }

            $source->status = SourceStatus::Rejected;
            $source->rejection_reason = $reason;

            return $source->save();
        });

        $this->refresh();

        return $changed;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_subject_type' => SubjectType::class,
            'mode' => IngestMode::class,
            'status' => SourceStatus::class,
            'rejection_reason' => InputRejectionReason::class,
            'source_url' => 'encrypted',
            'source_validator' => 'encrypted',
            'multipart_upload_id' => 'encrypted',
            'retry_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    private function transitionTo(SourceStatus $nextStatus): bool
    {
        $changed = DB::transaction(function () use ($nextStatus): bool {
            $source = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($source->status === $nextStatus || $source->status->isTerminal()) {
                return false;
            }

            if (($nextStatus === SourceStatus::Ready && $source->status !== SourceStatus::Ingesting)
                || ! in_array($nextStatus, [SourceStatus::Ready, SourceStatus::Failed], true)) {
                throw new LogicException("Source cannot transition from {$source->status->value} to {$nextStatus->value}.");
            }

            $source->status = $nextStatus;

            if ($nextStatus === SourceStatus::Ready) {
                $source->source_url = null;
                $source->multipart_upload_id = null;
            }

            return $source->save();
        });

        $this->refresh();

        return $changed;
    }

    private static function expect(
        Subject $owner,
        IngestMode $mode,
        SourceStatus $status,
        ?string $sourceUrl,
        ?int $bytes,
        ?string $contentType,
        ?DateTimeInterface $retryUntil,
        ?DateTimeInterface $expiresAt,
    ): self {
        $source = new self;
        $source->id = Str::uuid()->toString();
        $source->owner_subject_type = $owner->type;
        $source->owner_subject_ref = $owner->ref;
        $source->object_key = SourceObjectKeys::object($source->id)->value();
        $source->mode = $mode;
        $source->status = $status;
        $source->source_url = $sourceUrl;
        $source->bytes = $bytes ?? 0;
        $source->content_type = $contentType;
        $source->retry_until = $retryUntil === null ? null : CarbonImmutable::instance($retryUntil);
        $source->expires_at = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);
        $source->save();

        return $source;
    }
}
