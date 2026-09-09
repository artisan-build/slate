<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\RunTrust;
use App\Models\SourceStatus;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunAdmissionPolicy;
use App\Slate\Run\RunApiErrorReason;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunRecipeOperation;
use App\Slate\Source\AuthenticatedSourceScope;
use App\Slate\Webhook\WebhookDestinationGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

final class StoreRunRequest extends FormRequest
{
    private ?RunRecipe $runRecipe = null;

    private ?RunAdmissionPolicy $admissionPolicy = null;

    private ?InputRejectionReason $inputRejectionReason = null;

    private RunApiErrorReason $apiErrorReason = RunApiErrorReason::MalformedRecipe;

    private ?AuthenticatedSourceScope $sourceScope = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(
        RunAdmissionPolicy $admissionPolicy,
        WebhookDestinationGuard $webhookGuard,
        AuthenticatedSourceScope $sourceScope,
    ): array {
        $this->admissionPolicy = $admissionPolicy;
        $this->sourceScope = $sourceScope;

        return [
            'id' => ['required', 'string', 'uuid'],
            'recipe' => ['required', 'array'],
            'trust' => ['sometimes', Rule::enum(RunTrust::class)],
            'webhook_url' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                'url:https',
                'max:2048',
                function (string $attribute, mixed $value, callable $fail) use ($webhookGuard): void {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        $webhookGuard->resolve($value);
                    } catch (InvalidArgumentException $exception) {
                        $this->apiErrorReason = RunApiErrorReason::InvalidWebhookDestination;
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actualKeys = array_keys($this->all());
            $allowedKeys = ['id', 'recipe', 'trust', 'webhook_url'];
            $unexpectedKeys = array_diff($actualKeys, $allowedKeys);

            if ($unexpectedKeys !== []) {
                $validator->errors()->add('payload', 'The run payload contains unsupported fields.');
            }

            if ($validator->errors()->hasAny(['id', 'recipe'])) {
                return;
            }

            $runId = $this->input('id');
            $recipe = $this->input('recipe');

            if (! is_string($runId) || ! is_array($recipe)) {
                return;
            }

            try {
                /** @var array<string, mixed> $recipe */
                $sourceCache = [];
                $this->runRecipe = RunRecipe::fromWireArray(
                    $recipe,
                    function (string $sourceId) use (&$sourceCache) {
                        $source = $sourceCache[$sourceId] ??= $this->sourceScope?->find($sourceId);

                        if ($source === null) {
                            $this->apiErrorReason = RunApiErrorReason::SourceNotFound;

                            throw new InvalidArgumentException('A referenced recipe source was not found.');
                        }

                        if ($source->status !== SourceStatus::Ready) {
                            $this->apiErrorReason = RunApiErrorReason::SourceNotReady;

                            throw new InvalidArgumentException('A referenced recipe source is not ready.');
                        }

                        return $source->objectKey();
                    },
                );
                $operationClasses = array_unique(array_map(
                    static fn (RunRecipeOperation $operation): string => $operation::class,
                    $this->runRecipe->operations,
                ));

                if (count($operationClasses) > 1) {
                    throw new InvalidArgumentException('A run cannot mix source clips with assembler operations.');
                }

                $reason = $this->admissionPolicy?->rejectionReason($this->runRecipe);

                if ($reason !== null) {
                    $this->inputRejectionReason = $reason;
                    $validator->errors()->add(
                        'recipe',
                        $this->admissionPolicy?->message($reason) ?? 'The run exceeds its execution limits.',
                    );
                }
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('recipe', $exception->getMessage());
            }
        }];
    }

    public function runId(): string
    {
        return $this->string('id')->toString();
    }

    public function runRecipe(): RunRecipe
    {
        return $this->runRecipe ?? throw new LogicException('A validated run request must contain a typed recipe.');
    }

    public function runTrust(): RunTrust
    {
        return RunTrust::from($this->string('trust', RunTrust::Untrusted->value)->toString());
    }

    public function webhookUrl(): ?string
    {
        $webhookUrl = $this->input('webhook_url');

        return is_string($webhookUrl) ? $webhookUrl : null;
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The submitted run is invalid.',
            'reason' => $this->inputRejectionReason === null
                ? $this->apiErrorReason->value
                : $this->inputRejectionReason->value,
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
