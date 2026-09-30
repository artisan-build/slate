<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Slate\Http\DestinationGuard;
use App\Slate\Ingest\IngestManager;
use App\Slate\Mcp\McpAuthenticatedSubject;
use App\Slate\Mcp\McpWriteRefused;
use App\Slate\Mcp\McpWriteService;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Creates one HTTPS remote source for the caller. Upload URLs are not supported.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Write)]
final class CreateRemoteSourceTool extends ClosedInputTool
{
    protected string $name = 'create_remote_source';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(200)->required(),
            'url' => $schema->string()->min(1)->max(2048)->format('uri')->required(),
        ];
    }

    public function handle(
        Request $request,
        McpAuthenticatedSubject $authenticatedSubject,
        McpWriteService $writes,
        DestinationGuard $destinationGuard,
        IngestManager $ingestManager,
    ): Response|ResponseFactory {
        $idempotencyKey = $request->get('idempotency_key');
        $url = $request->get('url');

        if (! $this->hasExactArguments($request, ['idempotency_key', 'url'])
            || ! is_string($idempotencyKey)
            || $idempotencyKey === ''
            || Str::length($idempotencyKey) > 200
            || ! is_string($url)
            || $url === ''
            || Str::length($url) > 2048) {
            return Response::error('invalid_arguments');
        }

        try {
            $owner = $authenticatedSubject->resolve();
            $sourceId = Str::uuid()->toString();
            $result = $writes->execute(
                $owner,
                $this->name,
                $idempotencyKey,
                ['url' => $url],
                'source',
                $sourceId,
                function () use ($destinationGuard, $ingestManager, $owner, $sourceId, $url): array {
                    try {
                        $destinationGuard->resolve($url, 'source URL');
                    } catch (InvalidArgumentException) {
                        throw new McpWriteRefused('unsafe_source_url');
                    }

                    $source = $ingestManager->queueRemoteSource($owner, $url, $sourceId);

                    return ['id' => $source->id];
                },
            );

            return Response::structured($result->toArray());
        } catch (Throwable $exception) {
            report($exception);

            return Response::structured(['outcome' => 'outcome_unknown']);
        }
    }
}
