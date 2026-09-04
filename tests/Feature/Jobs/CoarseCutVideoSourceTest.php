<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CoarseCutVideoSource;
use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\ProbeCompiler;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\FakeDriver;
use App\Slate\Execution\CoarseCutRange;
use App\Slate\Execution\CoarseCutSource;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectedException;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Run\RunRecipe;
use App\Slate\Run\RunVideoClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Storage\ScratchPath;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CoarseCutVideoSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rejects_a_playlist_and_records_the_reason_before_any_binary_invocation(): void
    {
        Storage::fake();
        $runId = '61616161-6161-4161-8161-616161616161';
        $source = RunObjectKeys::input($runId, 0, 'source.mp4');
        $run = Run::createWithRecipe($runId, new RunRecipe([
            new RunVideoClip($source, 1.0, 2.0),
        ]), new Subject(SubjectType::Application, 'coarse-cut-tests'));
        $run->transitionTo(RunStatus::Running);
        $coarseOutput = RunObjectKeys::coarseClip($runId, 0);
        $operation = new CoarseCutSource($source, [
            new CoarseCutRange(0, 1_000_000, 2_000_000, $coarseOutput),
        ]);
        $objectStorage = $this->mock(ObjectStorage::class);
        $objectStorage->shouldReceive('stageIn')
            ->once()
            ->withArgs(function ($key, ScratchPath $path) use ($source): bool {
                file_put_contents($path->writablePath(), "#EXTM3U\nhttp://attacker.test/segment.ts\n");

                return $key->value() === $source->value();
            });
        $objectStorage->shouldNotReceive('stageOut');
        $driver = new FakeDriver;
        $compiler = new RecipeCompiler(new ProbeCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')));

        try {
            (new CoarseCutVideoSource($runId, $operation))->handle(
                $objectStorage,
                resolve(JobScratch::class),
                new InputAdmission,
                $compiler,
                $driver,
                new FanoutStorageGuard(Storage::disk(), true),
            );
            self::fail('Expected the playlist to be rejected.');
        } catch (InputRejectedException $exception) {
            self::assertSame(InputRejectionReason::ReferencedMedia, $exception->reason);
        }

        $run->refresh();
        self::assertSame([], $driver->recipes());
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame(InputRejectionReason::ReferencedMedia, $run->rejection_reason);
    }
}
