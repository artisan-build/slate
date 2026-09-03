<?php

use App\Jobs\RenderFanoutOperation;
use App\Services\FanoutHybridDelivery;
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
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectKey;
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

    Bus::assertBatched(function (PendingBatch $batch): bool {
        return $batch->name === 'Slate fan-out 11111111-1111-4111-8111-111111111111'
            && $batch->jobs->count() === 5
            && $batch->jobs->every(static fn (object $job): bool => $job instanceof RenderFanoutOperation)
            && count($batch->finallyCallbacks()) === 1;
    });
});

test('uses deterministic run and operation indexes for every transient object', function (): void {
    Storage::fake();
    $runId = FanoutRunId::fromString('22222222-2222-4222-8222-222222222222');

    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($runId): void {
        $inputs = [
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'first')),
            fakeRenderedSlateVideo(fanoutDispatchSource($scratch, 'second')),
        ];
        $run = resolve(FanoutHybridDelivery::class)->prepare(
            $inputs,
            24,
            QualityTier::Delivery,
            $runId,
        );

        expect(array_map(
            static fn (ObjectKey $key): string => $key->value(),
            $run->workObjectKeys(),
        ))->toBe([
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/inputs/0000.mp4',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/inputs/0001.mp4',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/operations/0000.mp4',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/receipts/0000.json',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/operations/0001.mp4',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/receipts/0001.json',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/operations/0002.mp4',
            'slate-fanout/22222222-2222-4222-8222-222222222222/work/receipts/0002.json',
        ]);
    });
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
