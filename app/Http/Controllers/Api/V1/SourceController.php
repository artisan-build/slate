<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSourceRequest;
use App\Slate\Ingest\IngestManager;
use App\Slate\Source\AuthenticatedSourceScope;
use App\Slate\Source\SourceApiErrorReason;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class SourceController extends Controller
{
    public function store(
        StoreSourceRequest $request,
        IngestManager $ingestManager,
        AuthenticatedSourceScope $sourceScope,
    ): JsonResponse {
        if (! $request->isUpload()) {
            $source = $ingestManager->queueRemoteSource($sourceScope->subject(), $request->sourceUrl());

            return response()->json(['id' => $source->id], 202);
        }

        $ttlSeconds = config('slate.api.source_upload_ttl_seconds');

        if (! is_int($ttlSeconds) || $ttlSeconds < 1) {
            throw new InvalidArgumentException('The source upload URL lifetime must be a positive integer.');
        }

        $expiration = now()->addSeconds($ttlSeconds);
        $upload = $ingestManager->presignedSource(
            $sourceScope->subject(),
            $request->contentType(),
            $request->bytes(),
            $expiration,
        );

        return response()->json([
            'id' => $upload->id,
            'put_url' => $upload->url,
            'headers' => $upload->headers,
            'expires_at' => $expiration->toISOString(),
            'object_key' => $upload->objectKey->value(),
        ], 201);
    }

    public function show(
        string $source,
        AuthenticatedSourceScope $sourceScope,
        IngestManager $ingestManager,
    ): JsonResponse {
        $source = $sourceScope->find($source);

        if ($source === null) {
            return response()->json([
                'message' => 'The source was not found.',
                'reason' => SourceApiErrorReason::SourceNotFound->value,
            ], 404);
        }

        $ingestManager->dispatchFinalizeIfUploaded($source);
        $source->refresh();

        return response()->json([
            'id' => $source->id,
            'status' => $source->status->value,
            'rejection_reason' => $source->rejection_reason?->value,
            'bytes' => $source->bytes,
            'content_type' => $source->content_type,
        ]);
    }
}
