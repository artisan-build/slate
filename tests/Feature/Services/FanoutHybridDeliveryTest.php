<?php

use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderSingleJobHybridDelivery;
use App\Services\FanoutHybridDelivery;
use App\Services\FanoutRunCleanup;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Fanout\BucketOperation;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

function fanoutDispatchSource(ScratchDirectory $scratch, string $name): VideoStillClip
{
    return new VideoStillClip(
        $scratch->file($name.'.png'),
        $scratch->file($name.'.mp3'),
        $scratch->file($name.'.mp4'),
        1536,
        1024,
        24,
        3.0,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Delivery,
        AudioBitrate::Kbps192,
    );
}

test('stages every source and dispatches one typed bucket operation per hybrid segment', function (): void {
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = FanoutRunId::fromString('11111111-1111-4111-8111-111111111111');

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($runId): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'third')),
        ];

        $run = resolve(FanoutHybridDelivery::class)->queue(
            $inputs,
            24,
            QualityTier::Delivery,
            $runId,
        );

        expect($run->id)->toBe($runId)
            ->and($run->operations)->toHaveCount(5)
            ->and($run->operations[0])->toBeInstanceOf(BucketVideoTrim::class)
            ->and($run->operations[1])->toBeInstanceOf(BucketVideoTransitionSegment::class)
            ->and($run->operations[2])->toBeInstanceOf(BucketVideoTrim::class)
            ->and($run->operations[3])->toBeInstanceOf(BucketVideoTransitionSegment::class)
            ->and($run->operations[4])->toBeInstanceOf(BucketVideoTrim::class)
            ->and(array_map(
                static fn (BucketOperation $operation): int => $operation->index(),
                $run->operations,
            ))->toBe([0, 1, 2, 3, 4]);

        foreach ($run->inputs as $input) {
            Storage::disk()->assertExists($input->objectKey->value());
        }
    });

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === 'Slate fan-out 11111111-1111-4111-8111-111111111111'
        && $batch->jobs->count() === 5
        && $batch->jobs->every(static fn (object $job): bool => $job instanceof RenderFanoutOperation)
        && count($batch->finallyCallbacks()) === 1);
});

test('uses deterministic run and operation indexes for every transient object', function (): void {
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = FanoutRunId::fromString('22222222-2222-4222-8222-222222222222');

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($runId): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
        ];
        $run = resolve(FanoutHybridDelivery::class)->queue(
            $inputs,
            24,
            QualityTier::Delivery,
            $runId,
        );

        expect(array_map(
            static fn (ObjectKey $key): string => $key->value(),
            $run->workObjectKeys(),
        ))->toBe([
            'slate-runs/22222222-2222-4222-8222-222222222222/work/inputs/0000.mp4',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/inputs/0001.mp4',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/operations/0000.mp4',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/receipts/0000.json',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/operations/0001.mp4',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/receipts/0001.json',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/operations/0002.mp4',
            'slate-runs/22222222-2222-4222-8222-222222222222/work/receipts/0002.json',
        ]);
    });
});

test('stages the same typed hybrid run and dispatches it as one queued video job', function (): void {
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = FanoutRunId::fromString('12121212-1212-4121-8121-121212121212');

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($runId): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'third')),
        ];

        $run = resolve(FanoutHybridDelivery::class)->queueSingle(
            $inputs,
            24,
            QualityTier::Delivery,
            $runId,
        );

        expect($run->operations)->toHaveCount(5);

        foreach ($run->inputs as $input) {
            Storage::disk()->assertExists($input->objectKey->value());
        }
    });

    Bus::assertDispatched(fn (RenderSingleJobHybridDelivery $job): bool => $job->run->id === $runId
        && count($job->run->operations) === 5);
    Bus::assertNothingBatched();
});

test('single video job body dispatches no child jobs or batches', function (): void {
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $driver = new readonly class implements Driver
    {
        public function run(CompiledRecipe $recipe): DriverResult
        {
            $command = $recipe->command();
            $output = $command[count($command) - 1];

            if (in_array('-show_format', $command, true)) {
                $frameCount = match (basename($output)) {
                    'input-00.mp4', 'input-01.mp4' => 72,
                    'hybrid-body-00.mp4', 'hybrid-body-01.mp4' => 48,
                    'hybrid-junction-00.mp4' => 24,
                    'output.mp4' => 120,
                    default => throw new RuntimeException('Unexpected single-job probe output.'),
                };
                $properties = [
                    'streams' => [
                        [
                            'codec_type' => 'video',
                            'codec_name' => 'h264',
                            'width' => 1536,
                            'height' => 1024,
                            'avg_frame_rate' => '24/1',
                            'pix_fmt' => 'yuv420p',
                            'color_range' => 'tv',
                            'nb_frames' => (string) $frameCount,
                        ],
                        ['codec_type' => 'audio', 'codec_name' => 'aac'],
                    ],
                    'format' => ['duration' => number_format($frameCount / 24, 6, '.', '')],
                ];

                return new DriverResult(json_encode($properties, JSON_THROW_ON_ERROR));
            }

            file_put_contents($output, 'rendered single-job operation');

            return new DriverResult;
        }
    };

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($driver): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
        ];
        $run = resolve(FanoutHybridDelivery::class)->queueSingle(
            $inputs,
            24,
            QualityTier::Delivery,
        );
        $compiler = RecipeCompiler::fromSystem();
        $job = new RenderSingleJobHybridDelivery($run);
        Bus::fake();

        $job->handle(
            resolve(ObjectStorage::class),
            resolve(JobScratch::class),
            $compiler,
            $driver,
            new HybridDeliveryAssembly($compiler, $driver),
            resolve(FanoutRunCleanup::class),
            resolve(FanoutStorageGuard::class),
        );

        Bus::assertNothingDispatched();
        Bus::assertBatchCount(0);
    });
});

test('refuses to stage or dispatch fan-out work on a host-local default disk', function (): void {
    Storage::fake();
    Bus::fake();
    config()->set('slate.storage.allow_host_local_fanout', false);

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
        ];

        expect(fn (): FanoutRun => resolve(FanoutHybridDelivery::class)->queue(
            $inputs,
            24,
            QualityTier::Delivery,
        ))->toThrow(RuntimeException::class, 'Slate fan-out requires an S3-backed default filesystem');
    });

    Bus::assertNothingBatched();
    expect(Storage::disk()->allFiles())->toBe([]);
});

test('rejects a run recipe whose input key is not derived from its run and index', function (): void {
    $runId = FanoutRunId::fromString('77777777-7777-4777-8777-777777777777');
    $prefix = $runId->prefix();
    $wrongInput = new StoredVideo(
        ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/not-an-index.mp4'),
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );
    $operation = new BucketVideoTrim(
        0,
        $wrongInput,
        0,
        48,
        ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4'),
    );

    expect(fn (): FanoutRun => new FanoutRun($runId, [$wrongInput], [$operation]))
        ->toThrow(InvalidArgumentException::class, 'derive from the run ID and input index');
});

test('rejects an operation that references a stored video outside the run inputs', function (): void {
    $runId = FanoutRunId::fromString('88888888-8888-4888-8888-888888888888');
    $prefix = $runId->prefix();
    $input = new StoredVideo(
        ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0000.mp4'),
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );
    $foreignPrefix = 'slate-runs/99999999-9999-4999-8999-999999999999';
    $foreignInput = new StoredVideo(
        ObjectKey::withinPrefix($foreignPrefix, $foreignPrefix.'/work/inputs/0000.mp4'),
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );
    $operation = new BucketVideoTrim(
        0,
        $foreignInput,
        0,
        48,
        ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4'),
    );

    expect(fn (): FanoutRun => new FanoutRun($runId, [$input], [$operation]))
        ->toThrow(InvalidArgumentException::class, 'only the run input descriptors');
});

test('does not expose staging-only or dispatch-only entry points', function (): void {
    $service = new ReflectionClass(FanoutHybridDelivery::class);

    expect($service->getMethod('prepare')->isPrivate())->toBeTrue()
        ->and($service->getMethod('dispatch')->isPrivate())->toBeTrue();
});
