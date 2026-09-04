<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunStillClip;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\RunObjectKeys;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $id = Str::uuid()->toString();
        $prefix = RunObjectKeys::prefix($id);

        return [
            'id' => $id,
            'recipe' => new RunRecipe([
                new RunStillClip(
                    ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0000/image.ppm'),
                    ObjectKey::withinPrefix($prefix, $prefix.'/inputs/0000/audio.mp3'),
                    1536,
                    1024,
                    24,
                    30_000_000,
                    VideoFit::ContainPad,
                    VideoColor::Black,
                    1,
                    QualityTier::Delivery,
                    AudioBitrate::Kbps192,
                ),
            ]),
            'status' => RunStatus::Queued,
        ];
    }
}
