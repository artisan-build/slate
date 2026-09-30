<?php

declare(strict_types=1);

namespace App\Slate\Mcp;

use App\Models\McpIdempotencyClaim;
use ArtisanBuild\BuiltForCloud\Subject;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class McpWriteService
{
    private const int LeaseSeconds = 300;

    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(): array<string, mixed>  $operation
     */
    public function execute(
        Subject $owner,
        string $tool,
        string $idempotencyKey,
        array $intent,
        string $targetType,
        string $targetId,
        Closure $operation,
    ): McpWriteResult {
        $intentHash = hash('sha256', $this->canonicalJson($intent));
        $executionToken = Str::uuid()->toString();
        $claim = $this->claim(
            $owner,
            $tool,
            $idempotencyKey,
            $intentHash,
            $targetType,
            $targetId,
            $executionToken,
        );

        if ($claim instanceof McpWriteResult) {
            return $claim;
        }

        try {
            $response = DB::transaction(function () use ($claim, $executionToken, $operation): array {
                $locked = McpIdempotencyClaim::query()->lockForUpdate()->findOrFail($claim->id);

                if ($locked->status !== 'in_progress' || $locked->execution_token !== $executionToken) {
                    throw new McpWriteRefused('execution_superseded');
                }

                $response = $operation();
                $locked->status = 'accepted';
                $locked->response = $response;
                $locked->execution_token = null;
                $locked->lease_expires_at = null;
                $locked->save();

                return $response;
            }, attempts: 3);

            return new McpWriteResult('accepted', $response);
        } catch (McpWriteRefused $refused) {
            return $this->settleFailure($claim->id, $executionToken, 'refused', $refused->reason);
        } catch (Throwable $exception) {
            report($exception);

            return $this->settleFailure($claim->id, $executionToken, 'outcome_unknown');
        }
    }

    private function claim(
        Subject $owner,
        string $tool,
        string $idempotencyKey,
        string $intentHash,
        string $targetType,
        string $targetId,
        string $executionToken,
    ): McpIdempotencyClaim|McpWriteResult {
        try {
            return DB::transaction(function () use (
                $owner,
                $tool,
                $idempotencyKey,
                $intentHash,
                $targetType,
                $targetId,
                $executionToken,
            ): McpIdempotencyClaim {
                $claim = new McpIdempotencyClaim;
                $claim->id = Str::uuid()->toString();
                $claim->owner_subject_type = $owner->type->value;
                $claim->owner_subject_ref = $owner->ref;
                $claim->tool = $tool;
                $claim->idempotency_key = $idempotencyKey;
                $claim->intent_hash = $intentHash;
                $claim->target_type = $targetType;
                $claim->target_id = $targetId;
                $claim->status = 'in_progress';
                $claim->execution_token = $executionToken;
                $claim->lease_expires_at = now()->toImmutable()->addSeconds(self::LeaseSeconds);
                $claim->save();

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingClaim($owner, $tool, $idempotencyKey, $intentHash);
        }
    }

    private function existingClaim(
        Subject $owner,
        string $tool,
        string $idempotencyKey,
        string $intentHash,
    ): McpWriteResult {
        $claim = McpIdempotencyClaim::query()
            ->where('owner_subject_type', $owner->type->value)
            ->where('owner_subject_ref', $owner->ref)
            ->where('tool', $tool)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        if (! hash_equals($claim->intent_hash, $intentHash)) {
            return new McpWriteResult('refused', reason: 'idempotency_key_reused');
        }

        if ($claim->status !== 'in_progress') {
            return $this->replay($claim);
        }

        if ($claim->lease_expires_at !== null && $claim->lease_expires_at->isFuture()) {
            return new McpWriteResult('in_progress');
        }

        return DB::transaction(function () use ($claim): McpWriteResult {
            $claim = McpIdempotencyClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->status !== 'in_progress') {
                return $this->replay($claim);
            }

            if ($claim->lease_expires_at !== null && $claim->lease_expires_at->isFuture()) {
                return new McpWriteResult('in_progress');
            }

            $claim->status = 'outcome_unknown';
            $claim->execution_token = null;
            $claim->lease_expires_at = null;
            $claim->save();

            return $this->replay($claim);
        });
    }

    private function settleFailure(
        string $claimId,
        string $executionToken,
        string $status,
        ?string $reason = null,
    ): McpWriteResult {
        try {
            return DB::transaction(function () use (
                $claimId,
                $executionToken,
                $status,
                $reason,
            ): McpWriteResult {
                $claim = McpIdempotencyClaim::query()->lockForUpdate()->findOrFail($claimId);

                if ($claim->status === 'accepted') {
                    return new McpWriteResult('accepted', $claim->response ?? []);
                }

                if ($claim->status !== 'in_progress' || $claim->execution_token !== $executionToken) {
                    return $this->replay($claim);
                }

                $claim->status = $status;
                $claim->response = $reason === null ? null : ['reason' => $reason];
                $claim->execution_token = null;
                $claim->lease_expires_at = null;
                $claim->save();

                return new McpWriteResult($status, reason: $reason);
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);

            return new McpWriteResult('outcome_unknown');
        }
    }

    private function replay(McpIdempotencyClaim $claim): McpWriteResult
    {
        if ($claim->status === 'accepted') {
            return new McpWriteResult('already_accepted', $claim->response ?? []);
        }

        if ($claim->status === 'refused') {
            $reason = $claim->response['reason'] ?? null;

            return new McpWriteResult('refused', reason: is_string($reason) ? $reason : null);
        }

        return new McpWriteResult($claim->status === 'in_progress' ? 'in_progress' : 'outcome_unknown');
    }

    /** @param array<string, mixed> $intent */
    private function canonicalJson(array $intent): string
    {
        try {
            return json_encode(
                $this->sortObjects($intent),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new McpWriteRefused('invalid_arguments');
        }
    }

    private function sortObjects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->sortObjects(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->sortObjects(...), $value);
    }
}
