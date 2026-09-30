<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Mcp\McpAuthenticatedSubject;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Returns a short-lived signed final-result URL for one succeeded run owned by the caller.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RunResultUrlTool extends ClosedInputTool
{
    private const int MaximumUrlBytes = 16_384;

    protected string $name = 'run_result_url';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->min(36)->max(36)->format('uuid')->required(),
        ];
    }

    public function handle(
        Request $request,
        McpAuthenticatedSubject $authenticatedSubject,
        FanoutStorageGuard $storageGuard,
        ObjectStorage $objectStorage,
    ): Response|ResponseFactory {
        $runId = $request->get('run_id');

        if (! $this->hasExactArguments($request, ['run_id'])
            || ! is_string($runId)
            || ! Str::isUuid($runId)) {
            return Response::error('invalid_arguments');
        }

        try {
            $run = Run::ownedBy($authenticatedSubject->resolve())->find($runId);

            if ($run === null) {
                return Response::error('not_found');
            }

            if ($run->status !== RunStatus::Succeeded) {
                return Response::error('result_not_ready');
            }

            $ttlSeconds = config('slate.api.result_url_ttl_seconds');

            if (! is_int($ttlSeconds) || $ttlSeconds < 1) {
                return Response::error('unavailable');
            }

            $storageGuard->assertSafe();
            $expiresAt = now()->addSeconds($ttlSeconds);
            $url = $objectStorage->temporaryUrl(RunObjectKeys::deliveryOutput($run->id), $expiresAt);

            if (strlen($url) > self::MaximumUrlBytes) {
                return Response::error('unavailable');
            }

            return Response::structured([
                'id' => $run->id,
                'expires_at' => $expiresAt->toISOString(),
                'url' => $url,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('unavailable');
        }
    }
}
