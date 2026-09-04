<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Models\Run;
use App\Slate\Execution\RunExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class RunController extends Controller
{
    public function store(StoreRunRequest $request, RunExecutor $executor): JsonResponse
    {
        $webhookUrl = $request->webhookUrl();
        $webhookSecret = $webhookUrl === null ? null : Str::random(64);
        $run = Run::createWithRecipe(
            $request->runId(),
            $request->runRecipe(),
            $request->runTrust(),
            $webhookUrl,
            $webhookSecret,
        );

        $executor->execute($run);

        $response = ['id' => $run->id];

        if ($webhookSecret !== null) {
            $response['webhook_secret'] = $webhookSecret;
        }

        return response()->json($response, 202);
    }

    public function show(Run $run): JsonResponse
    {
        return response()->json([
            'id' => $run->id,
            'status' => $run->status->value,
            'trust' => $run->trust->value,
            'rejection_reason' => $run->rejection_reason?->value,
        ]);
    }
}
