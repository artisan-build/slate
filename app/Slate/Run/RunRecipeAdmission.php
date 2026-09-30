<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Models\Source;
use App\Models\SourceStatus;
use ArtisanBuild\BuiltForCloud\Subject;
use InvalidArgumentException;

final readonly class RunRecipeAdmission
{
    public function __construct(private RunAdmissionPolicy $admissionPolicy) {}

    /** @param array<string, mixed> $payload */
    public function admit(array $payload, Subject $owner): RunRecipe
    {
        try {
            $sourceCache = [];
            $recipe = RunRecipe::fromWireArray(
                $payload,
                function (string $sourceId) use (&$sourceCache, $owner) {
                    $source = $sourceCache[$sourceId] ??= Source::ownedBy($owner)->find($sourceId);

                    if ($source === null) {
                        throw new RunSubmissionException(
                            RunApiErrorReason::SourceNotFound,
                            'A referenced recipe source was not found.',
                        );
                    }

                    if ($source->status !== SourceStatus::Ready) {
                        throw new RunSubmissionException(
                            RunApiErrorReason::SourceNotReady,
                            'A referenced recipe source is not ready.',
                        );
                    }

                    return $source->objectKey();
                },
            );
            $operationClasses = array_unique(array_map(
                static fn (RunRecipeOperation $operation): string => $operation::class,
                $recipe->operations,
            ));

            if (count($operationClasses) > 1) {
                throw new InvalidArgumentException('A run cannot mix source clips with assembler operations.');
            }

            $reason = $this->admissionPolicy->rejectionReason($recipe);

            if ($reason !== null) {
                throw new RunSubmissionException($reason, $this->admissionPolicy->message($reason));
            }

            return $recipe;
        } catch (RunSubmissionException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new RunSubmissionException(RunApiErrorReason::MalformedRecipe, $exception->getMessage());
        }
    }
}
