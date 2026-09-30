<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Run;
use App\Slate\Mcp\McpAuthenticatedSubject;
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

#[Description('Returns the status, trust, and rejection reason for one run owned by the caller.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class RunStatusTool extends ClosedInputTool
{
    protected string $name = 'run_status';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->min(36)->max(36)->format('uuid')->required(),
        ];
    }

    public function handle(Request $request, McpAuthenticatedSubject $authenticatedSubject): Response|ResponseFactory
    {
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

            return Response::structured([
                'id' => $run->id,
                'status' => $run->status->value,
                'trust' => $run->trust->value,
                'rejection_reason' => $run->rejection_reason?->value,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('unavailable');
        }
    }
}
