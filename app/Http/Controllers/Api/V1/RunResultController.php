<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RunStatus;
use App\Slate\Run\AuthenticatedRunScope;
use App\Slate\Run\RunApiErrorReason;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Http\JsonResponse;

final class RunResultController extends Controller
{
    public function __invoke(
        string $run,
        ObjectStorage $objectStorage,
        FanoutStorageGuard $storageGuard,
        AuthenticatedRunScope $runScope,
    ): JsonResponse {
        $run = $runScope->find($run);

        if ($run === null) {
            return response()->json([
                'message' => 'The run was not found.',
                'reason' => RunApiErrorReason::RunNotFound->value,
            ], 404);
        }

        if ($run->status !== RunStatus::Succeeded) {
            return response()->json([
                'reason' => $run->status === RunStatus::Failed
                    ? RunApiErrorReason::RunFailed->value
                    : RunApiErrorReason::ResultNotReady->value,
                'status' => $run->status->value,
                'rejection_reason' => $run->rejection_reason?->value,
            ], 409);
        }

        $storageGuard->assertSafe();
        $expiresAt = now()->addSeconds((int) config('slate.api.result_url_ttl_seconds'));
        $results = [];

        foreach (array_keys($run->recipe()->operations) as $operationIndex) {
            $results[] = [
                'operation_index' => $operationIndex,
                'url' => $objectStorage->temporaryUrl(
                    RunObjectKeys::operationOutput($run->id, $operationIndex),
                    $expiresAt,
                ),
            ];
        }

        return response()->json([
            'id' => $run->id,
            'expires_at' => $expiresAt->toISOString(),
            'results' => $results,
        ]);
    }
}
