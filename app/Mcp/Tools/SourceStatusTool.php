<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Source;
use App\Slate\Mcp\McpAuthenticatedSubject;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
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

#[Description('Returns the status and rejection reason for one source owned by the caller.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class SourceStatusTool extends ClosedInputTool
{
    protected string $name = 'source_status';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_id' => $schema->string()->min(36)->max(36)->format('uuid')->required(),
        ];
    }

    public function handle(
        Request $request,
        HttpRequest $httpRequest,
        ActingPrincipalResolver $principalResolver,
        McpAuthenticatedSubject $authenticatedSubject,
    ): Response|ResponseFactory {
        $owner = $authenticatedSubject->resolve($principalResolver->require($httpRequest));
        $sourceId = $request->get('source_id');

        if (! $this->hasExactArguments($request, ['source_id'])
            || ! is_string($sourceId)
            || ! Str::isUuid($sourceId)) {
            return Response::error('invalid_arguments');
        }

        try {
            $source = Source::ownedBy($owner)->find($sourceId);

            if ($source === null) {
                return Response::error('not_found');
            }

            return Response::structured([
                'id' => $source->id,
                'status' => $source->status->value,
                'rejection_reason' => $source->rejection_reason?->value,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('unavailable');
        }
    }
}
