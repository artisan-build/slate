<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\RunTrust;
use App\Slate\Mcp\McpAuthenticatedSubject;
use App\Slate\Mcp\McpWriteRefused;
use App\Slate\Mcp\McpWriteService;
use App\Slate\Recipe\QualityTier;
use App\Slate\Run\RunManager;
use App\Slate\Run\RunRecipeAdmission;
use App\Slate\Run\RunSubmissionException;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Creates one untrusted typed delivery or highlights run for the caller.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Write)]
final class CreateRunTool extends ClosedInputTool
{
    protected string $name = 'create_run';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(200)->required(),
            'run_id' => $schema->string()->min(36)->max(36)->format('uuid')->required(),
            'recipe' => $schema->anyOf([
                $schema->object([
                    'delivery' => $this->deliverySchema($schema)->required(),
                ])->withoutAdditionalProperties(),
                $schema->object([
                    'highlights' => $this->highlightsSchema($schema)->required(),
                ])->withoutAdditionalProperties(),
            ])->required(),
        ];
    }

    public function handle(
        Request $request,
        McpAuthenticatedSubject $authenticatedSubject,
        McpWriteService $writes,
        RunRecipeAdmission $recipeAdmission,
        RunManager $runManager,
    ): Response|ResponseFactory {
        $idempotencyKey = $request->get('idempotency_key');
        $runId = $request->get('run_id');
        $recipePayload = $request->get('recipe');

        if (! $this->hasExactArguments($request, ['idempotency_key', 'recipe', 'run_id'])
            || ! is_string($idempotencyKey)
            || $idempotencyKey === ''
            || Str::length($idempotencyKey) > 200
            || ! is_string($runId)
            || ! Str::isUuid($runId)
            || ! is_array($recipePayload)
            || $recipePayload === []
            || array_is_list($recipePayload)) {
            return Response::error('invalid_arguments');
        }

        try {
            $owner = $authenticatedSubject->resolve();
            /** @var array<string, mixed> $recipePayload */
            $result = $writes->execute(
                $owner,
                $this->name,
                $idempotencyKey,
                ['run_id' => $runId, 'recipe' => $recipePayload],
                'run',
                $runId,
                function () use ($owner, $recipeAdmission, $recipePayload, $runId, $runManager): array {
                    try {
                        $recipe = $recipeAdmission->admit($recipePayload, $owner);
                        $run = $runManager->create($runId, $recipe, $owner, RunTrust::Untrusted);
                    } catch (RunSubmissionException $exception) {
                        throw new McpWriteRefused($exception->reason->value);
                    } catch (UniqueConstraintViolationException) {
                        throw new McpWriteRefused('run_id_unavailable');
                    }

                    return ['id' => $run->id];
                },
            );

            return Response::structured($result->toArray());
        } catch (Throwable $exception) {
            report($exception);

            return Response::structured(['outcome' => 'outcome_unknown']);
        }
    }

    private function deliverySchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'pages' => $schema->array()
                ->min(2)
                ->max(49)
                ->items($schema->object([
                    'image' => $this->uuidSchema($schema)->required(),
                    'audio' => $this->uuidSchema($schema)->required(),
                    'duration' => $schema->number()->min(0.000001)->required(),
                    'captions' => $schema->object([
                        'font' => $this->uuidSchema($schema)->required(),
                        'words' => $schema->array()->min(1)->max(64)->items(
                            $schema->object([
                                'text' => $schema->string()->min(1)->max(128)->required(),
                                'x' => $schema->integer()->min(0)->required(),
                                'y' => $schema->integer()->min(0)->required(),
                                'start' => $schema->number()->min(0)->required(),
                                'end' => $schema->number()->min(0.000001)->required(),
                            ])->withoutAdditionalProperties(),
                        )->required(),
                    ])->withoutAdditionalProperties(),
                ])->withoutAdditionalProperties())
                ->required(),
            'transitions' => $this->transitionSchema($schema)->required(),
            'quality_tier' => $schema->string()->enum(QualityTier::class)->required(),
            'width' => $schema->integer()->min(1)->max(4096)->required(),
            'height' => $schema->integer()->min(1)->max(4096)->required(),
        ])->withoutAdditionalProperties();
    }

    private function highlightsSchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'source' => $this->uuidSchema($schema)->required(),
            'clips' => $schema->array()
                ->min(2)
                ->max(49)
                ->items($schema->object([
                    'start' => $schema->number()->min(0)->required(),
                    'end' => $schema->number()->min(0.000001)->required(),
                ])->withoutAdditionalProperties())
                ->required(),
            'transitions' => $this->transitionSchema($schema)->required(),
            'quality_tier' => $schema->string()->enum(QualityTier::class)->required(),
        ])->withoutAdditionalProperties();
    }

    private function transitionSchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'type' => $schema->string()->enum(['fade'])->required(),
            'duration' => $schema->number()->enum([1])->required(),
        ])->withoutAdditionalProperties();
    }

    private function uuidSchema(JsonSchema $schema): Type
    {
        return $schema->string()->min(36)->max(36)->format('uuid');
    }
}
