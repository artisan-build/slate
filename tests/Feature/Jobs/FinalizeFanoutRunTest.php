<?php

use App\Jobs\FinalizeFanoutRun;
use App\Services\FanoutRunCleanup;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Fanout\BucketVideoTransitionSegment;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutReceipt;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;
use Illuminate\Support\Facades\Storage;

function finalizerStoredVideo(ObjectKey $key): StoredVideo
{
    return new StoredVideo($key, 1536, 1024, 24, 72, 3.0, QualityTier::Delivery, 24);
}

function finalizerRun(): FanoutRun
{
    $id = FanoutRunId::fromString('44444444-4444-4444-8444-444444444444');
    $prefix = $id->prefix();
    $first = finalizerStoredVideo(ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0000.mp4'));
    $second = finalizerStoredVideo(ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0001.mp4'));

    return new FanoutRun($id, [$first, $second], [
        new BucketVideoTrim(
            0,
            $first,
            0,
            48,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4'),
        ),
        new BucketVideoTransitionSegment(
            1,
            $first,
            $second,
            24,
            QualityTier::Delivery,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0001.mp4'),
        ),
        new BucketVideoTrim(
            2,
            $second,
            24,
            48,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0002.mp4'),
        ),
    ]);
}

/** @param array<string, int> $frameCountsByBasename */
function finalizerDriver(array $frameCountsByBasename): Driver
{
    return new class($frameCountsByBasename) implements Driver
    {
        /** @param array<string, int> $frameCountsByBasename */
        public function __construct(private readonly array $frameCountsByBasename) {}

        public function run(CompiledRecipe $recipe): DriverResult
        {
            $command = $recipe->command();

            if (in_array('-show_format', $command, true)) {
                $basename = basename($command[count($command) - 1]);
                $frameCount = $this->frameCountsByBasename[$basename] ?? null;

                if ($frameCount === null) {
                    throw new RuntimeException("No finalizer probe properties for {$basename}.");
                }

                $properties = [
                    'streams' => [
                        [
                            'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1536,
                            'height' => 1024, 'avg_frame_rate' => '24/1', 'pix_fmt' => 'yuv420p',
                            'color_range' => 'tv', 'nb_frames' => (string) $frameCount,
                        ],
                        ['codec_type' => 'audio', 'codec_name' => 'aac'],
                    ],
                    'format' => ['duration' => number_format($frameCount / 24, 6, '.', '')],
                ];

                return new DriverResult(json_encode($properties, JSON_THROW_ON_ERROR));
            }

            file_put_contents($command[count($command) - 1], 'copy-concatenated fan-out output');

            return new DriverResult;
        }
    };
}

function stageFinalizerWork(FanoutRun $run, ObjectStorage $objectStorage, JobScratch $jobScratch): void
{
    $jobScratch->run(function (ScratchDirectory $scratch) use ($run, $objectStorage): void {
        foreach ($run->inputs as $index => $input) {
            $path = $scratch->file("input-{$index}.mp4");
            file_put_contents($path->writablePath(), 'input');
            $objectStorage->stageOut($path, $input->objectKey);
        }

        foreach ($run->operations as $operation) {
            $outputPath = $scratch->file("operation-{$operation->index()}.mp4");
            file_put_contents($outputPath->writablePath(), 'operation');
            $objectStorage->stageOut($outputPath, $operation->output()->objectKey);
            $hostname = match ($operation->index()) {
                0, 2 => 'worker-a',
                default => 'worker-b',
            };
            $receipt = new FanoutReceipt($operation->index(), $operation->name(), $hostname, 25, 9);
            $receiptPath = $scratch->file("receipt-{$operation->index()}.json");
            file_put_contents($receiptPath->writablePath(), $receipt->toJson());
            $objectStorage->stageOut($receiptPath, $run->receiptKey($operation->index()));
        }
    });
}

test('copy-concatenates staged operation outputs and records host evidence before cleaning work objects', function (): void {
    Storage::fake();
    $run = finalizerRun();
    $objectStorage = resolve(ObjectStorage::class);
    $jobScratch = resolve(JobScratch::class);
    $cleanup = resolve(FanoutRunCleanup::class);
    stageFinalizerWork($run, $objectStorage, $jobScratch);
    $driver = finalizerDriver([
        'segment-0000.mp4' => 48,
        'segment-0001.mp4' => 24,
        'segment-0002.mp4' => 48,
        'output.mp4' => 120,
    ]);
    $job = new FinalizeFanoutRun($run, false);

    $job->handle($objectStorage, $jobScratch, RecipeCompiler::fromSystem(), $driver, $cleanup);

    Storage::disk()->assertExists($run->outputKey()->value());
    Storage::disk()->assertExists($run->resultKey()->value());

    foreach ($run->workObjectKeys() as $key) {
        Storage::disk()->assertMissing($key->value());
    }

    $result = json_decode(Storage::disk()->get($run->resultKey()->value()), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($result, 'output.frame_count'))->toBe(120)
        ->and(data_get($result, 'output.duration_ms'))->toBe(5000)
        ->and(data_get($result, 'worker_hostnames'))->toBe(['worker-a', 'worker-b'])
        ->and(data_get($result, 'distinct_worker_count'))->toBe(2)
        ->and(data_get($result, 'multi_host_observed'))->toBeTrue()
        ->and(array_column(data_get($result, 'operations'), 'hostname'))->toBe([
            'worker-a', 'worker-b', 'worker-a',
        ])
        ->and(data_get($result, 'final_concat.mode'))->toBe('copy');
});

test('treats a completed output and result as an idempotent finalizer redelivery', function (): void {
    Storage::fake();
    $run = finalizerRun();
    Storage::disk()->put($run->outputKey()->value(), 'completed output');
    Storage::disk()->put($run->resultKey()->value(), '{"status":"completed"}');
    $driver = Mockery::mock(Driver::class);
    $driver->shouldNotReceive('run');

    (new FinalizeFanoutRun($run, false))->handle(
        resolve(ObjectStorage::class),
        resolve(JobScratch::class),
        RecipeCompiler::fromSystem(),
        $driver,
        resolve(FanoutRunCleanup::class),
    );

    expect(Storage::disk()->get($run->outputKey()->value()))->toBe('completed output')
        ->and(Storage::disk()->get($run->resultKey()->value()))->toBe('{"status":"completed"}');
});

test('removes every run object when an operation batch fails', function (): void {
    Storage::fake();
    $run = finalizerRun();

    foreach ($run->allObjectKeys() as $key) {
        Storage::disk()->put($key->value(), 'partial');
    }

    $driver = Mockery::mock(Driver::class);
    $driver->shouldNotReceive('run');
    (new FinalizeFanoutRun($run, true))->handle(
        resolve(ObjectStorage::class),
        resolve(JobScratch::class),
        RecipeCompiler::fromSystem(),
        $driver,
        resolve(FanoutRunCleanup::class),
    );

    expect(Storage::disk()->allFiles($run->id->prefix()))->toBe([]);
});

test('removes every run object after terminal finalizer failure', function (): void {
    Storage::fake();
    $run = finalizerRun();

    foreach ($run->allObjectKeys() as $key) {
        Storage::disk()->put($key->value(), 'partial');
    }

    (new FinalizeFanoutRun($run, false))->failed(new RuntimeException('copy concat failed'));

    expect(Storage::disk()->allFiles($run->id->prefix()))->toBe([]);
});
