<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Recipe\OperationName;
use App\Slate\Recipe\QualityTier;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunRecipeOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe' => new RunRecipe([
                new RunRecipeOperation(
                    OperationName::VideoStillClip,
                    QualityTier::Delivery,
                    30_000_000,
                    true,
                ),
            ]),
            'status' => RunStatus::Queued,
        ];
    }
}
