<?php

namespace Tests\Feature\Slate\Recipe\Video;

use App\Models\Run;
use App\Models\RunStatus;
use App\Models\RunTrust;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\RunObjectKeys;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class VideoClipTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_persists_source_start_end_and_declared_trust_as_typed_run_state(): void
    {
        $runId = '41414141-4141-4141-8141-414141414141';
        $source = RunObjectKeys::input($runId, 0, 'source.mp4');
        $recipe = new RunRecipe([new RunVideoClip($source, 12.5, 19.75)]);

        $run = Run::createWithRecipe(
            $runId,
            $recipe,
            new Subject(SubjectType::Application, 'video-clip-tests'),
            RunTrust::TrustedOrigin,
        )->fresh();

        self::assertNotNull($run);
        self::assertSame(RunTrust::TrustedOrigin, $run->trust);
        self::assertSame([
            'operations' => [[
                'operation' => 'video.clip',
                'source' => $source->value(),
                'start' => 12.5,
                'end' => 19.75,
                'quality_tier' => 'delivery',
            ]],
        ], $run->recipe()->toArray());
    }

    public function test_persists_a_typed_input_rejection_reason_on_the_run(): void
    {
        $run = Run::factory()->create(['trust' => RunTrust::Untrusted]);

        $changed = $run->reject(InputRejectionReason::DecodeCodecNotAllowed);

        self::assertTrue($changed);
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(InputRejectionReason::DecodeCodecNotAllowed, $run->rejection_reason);
        self::assertSame(RunTrust::Untrusted, $run->trust);
    }
}
