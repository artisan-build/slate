<?php

use App\Jobs\RenderFanoutOperation;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Execution\SkipIfBatchUnavailable;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutReceipt;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\FanoutStorageGuard;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\ObjectStorage;
use Illuminate\Support\Facades\Storage;

/** @return array<string, mixed> */
function fanoutProbeProperties(int $frameCount): array
{
    return [
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
}

/** @param array<string, int> $frameCountsByBasename */
function fanoutJobDriver(array $frameCountsByBasename): Driver
{
    return new readonly class($frameCountsByBasename) implements Driver
    {
        /** @param array<string, int> $frameCountsByBasename */
        public function __construct(private array $frameCountsByBasename) {}

        public function run(CompiledRecipe $recipe): DriverResult
        {
            $command = $recipe->command();

            if (in_array('-show_format', $command, true)) {
                $basename = basename($command[count($command) - 1]);
                $frameCount = $this->frameCountsByBasename[$basename] ?? null;

                if ($frameCount === null) {
                    throw new RuntimeException("No fake probe properties for {$basename}.");
                }

                return new DriverResult(json_encode(fanoutProbeProperties($frameCount), JSON_THROW_ON_ERROR));
            }

            file_put_contents($command[count($command) - 1], 'rendered fan-out operation');

            return new DriverResult;
        }
    };
}

test('stages bucket inputs into invocation scratch and overwrites deterministic outputs on redelivery', function (): void {
    Storage::fake();
    config()->set('slate.storage.allow_host_local_fanout', true);
    $runId = FanoutRunId::fromString('33333333-3333-4333-8333-333333333333');
    $prefix = $runId->prefix();
    $inputKey = ObjectKey::withinPrefix($prefix, $prefix.'/work/inputs/0000.mp4');
    $outputKey = ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4');
    $storedInput = new StoredVideo(
        $inputKey,
        1536,
        1024,
        24,
        72,
        3.0,
        QualityTier::Delivery,
        24,
    );
    $operation = new BucketVideoTrim(0, $storedInput, 0, 48, $outputKey);
    $job = new RenderFanoutOperation($runId, $operation);
    $objectStorage = resolve(ObjectStorage::class);
    $jobScratch = resolve(JobScratch::class);
    $compiler = RecipeCompiler::fromSystem();
    $driver = fanoutJobDriver(['input-00.mp4' => 72, 'output.mp4' => 48]);
    Storage::disk()->put($inputKey->value(), 'bucket source');

    $storageGuard = resolve(FanoutStorageGuard::class);
    $job->handle($objectStorage, $jobScratch, $compiler, $driver, $storageGuard);
    $firstOutput = Storage::disk()->get($outputKey->value());
    $job->handle($objectStorage, $jobScratch, $compiler, $driver, $storageGuard);

    Storage::disk()->assertExists($outputKey->value());
    Storage::disk()->assertExists($prefix.'/work/receipts/0000.json');
    expect(Storage::disk()->get($outputKey->value()))->toBe($firstOutput)
        ->and(Storage::disk()->allFiles($prefix))->toHaveCount(3);

    $receipt = FanoutReceipt::fromJson(
        Storage::disk()->get($prefix.'/work/receipts/0000.json'),
        $operation,
    );
    expect($receipt->operationIndex)->toBe(0)
        ->and($receipt->operation->value)->toBe('video.trim')
        ->and($receipt->hostname)->not->toBe('')
        ->and($receipt->outputSizeBytes)->toBeGreaterThan(0)
        ->and($job->middleware()[0])->toBeInstanceOf(SkipIfBatchUnavailable::class);
});
