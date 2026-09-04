<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Models\Run;
use App\Slate\Execution\RunExecutor;
use App\Slate\Run\AuthenticatedRunScope;
use App\Slate\Run\RunApiErrorReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class RunController extends Controller
{
    public function store(
        StoreRunRequest $request,
        RunExecutor $executor,
        AuthenticatedRunScope $runScope,
    ): JsonResponse {
        $webhookUrl = $request->webhookUrl();
        $webhookSecret = $webhookUrl === null ? null : Str::random(64);
        try {
            $run = Run::createWithRecipe(
                $request->runId(),
                $request->runRecipe(),
                $runScope->subject(),
                $request->runTrust(),
                $webhookUrl,
                $webhookSecret,
            );
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'The run id has already been submitted.',
                'reason' => RunApiErrorReason::RunIdConflict->value,
            ], 409);
        }

        $executor->execute($run);

        $response = ['id' => $run->id];

        if ($webhookSecret !== null) {
            $response['webhook_secret'] = $webhookSecret;
        }

        return response()->json($response, 202);
    }

    public function show(string $run, AuthenticatedRunScope $runScope): JsonResponse
    {
        $run = $runScope->find($run);

        if ($run === null) {
            return response()->json([
                'message' => 'The run was not found.',
                'reason' => RunApiErrorReason::RunNotFound->value,
            ], 404);
        }

        return response()->json([
            'id' => $run->id,
            'status' => $run->status->value,
            'trust' => $run->trust->value,
            'rejection_reason' => $run->rejection_reason?->value,
        ]);
    }
}
