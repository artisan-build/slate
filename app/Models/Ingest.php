<?php

namespace App\Models;

use App\Slate\Ingest\SourceMetadata;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Database\Factories\IngestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

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

        return self::expect($run, $objectKey, IngestMode::Source, $sourceUrl);
    }

    public static function expectDirect(Run $run, ObjectKey $objectKey): self
    {
        return self::expect($run, $objectKey, IngestMode::Direct, null);
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

        if ($this->expected_bytes !== null && $this->expected_bytes !== $metadata->bytes) {
            throw new RuntimeException('The source size changed while resuming ingest.');
        }

        if ($this->source_validator_header !== null
            && ($this->source_validator_header !== $metadata->validatorHeader
                || $this->source_validator !== $metadata->validator)) {
            throw new RuntimeException('The source changed while resuming ingest.');
        }

        $this->expected_bytes = $metadata->bytes;
        $this->source_validator_header = $metadata->validatorHeader;
        $this->source_validator = $metadata->validator;
        $this->part_size_bytes ??= $partSizeBytes;
        $this->multipart_upload_id ??= $uploadId;
        $this->status = IngestStatus::Uploading;
        $this->save();
    }

    public function markSucceeded(): void
    {
        $this->status = IngestStatus::Succeeded;
        $this->source_url = null;
        $this->multipart_upload_id = null;
        $this->save();
    }

    public function markFailed(): void
    {
        if ($this->status !== IngestStatus::Succeeded) {
            $this->status = IngestStatus::Failed;
            $this->save();
        }
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
        ];
    }

    private static function expect(
        Run $run,
        ObjectKey $objectKey,
        IngestMode $mode,
        ?string $sourceUrl,
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
        $ingest->save();

        return $ingest;
    }
}
