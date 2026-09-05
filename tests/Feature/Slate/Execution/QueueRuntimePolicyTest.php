<?php

use App\Jobs\FinalizeFanoutRun;
use App\Jobs\RenderFanoutOperation;
use App\Jobs\RenderSingleJobHybridDelivery;
use App\Slate\Driver\Binary\BinaryDriver;
use App\Slate\Driver\Driver;
use App\Slate\Execution\QueueRuntimePolicy;
use App\Slate\Execution\QueueRuntimeSettings;
use App\Slate\Execution\VisibilityWindowSource;
use App\Slate\Fanout\BucketVideoTrim;
use App\Slate\Fanout\FanoutRun;
use App\Slate\Fanout\FanoutRunId;
use App\Slate\Fanout\StoredVideo;
use App\Slate\Recipe\QualityTier;
use App\Slate\Storage\ObjectKey;

function configuredQueueRuntime(
    int $window,
    float $deliveryRate = 0.389,
    float $intermediateRate = 0.389,
): QueueRuntimeSettings {
    config()->set('queue.default', 'policy_test');
    config()->set('slate.execution.render_queue', 'render');
    config()->set('queue.connections.policy_test', [
        'driver' => 'database',
        'retry_after' => $window + 30,
        'queues' => [
            'render' => ['retry_after' => $window],
        ],
    ]);
    config()->set('slate.execution.measured_realtime_rates.delivery', $deliveryRate);
    config()->set('slate.execution.measured_realtime_rates.intermediate', $intermediateRate);

    return resolve(QueueRuntimePolicy::class)->forQueue('render');
}

function policyFanoutRun(): FanoutRun
{
    $id = FanoutRunId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $prefix = $id->prefix();
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

    return new FanoutRun($id, [$input], [
        new BucketVideoTrim(
            0,
            $input,
            0,
            48,
            ObjectKey::withinPrefix($prefix, $prefix.'/work/operations/0000.mp4'),
        ),
    ]);
}

test('derives every runtime limit for both tiers from two configured windows and rate points', function (): void {
    $first = configuredQueueRuntime(180, 0.389, 0.25);

    expect($first->visibilityWindowSeconds)->toBe(180)
        ->and($first->jobTimeoutSeconds)->toBe(170)
        ->and($first->driverTimeoutSeconds)->toBe(160)
        ->and($first->outputSecondsPerJob(QualityTier::Delivery))->toBe(49)
        ->and($first->outputSecondsPerJob(QualityTier::Intermediate))->toBe(32)
        ->and($first->visibilityWindowSource)->toBe(VisibilityWindowSource::NamedQueueRetryAfter)
        ->and($first->usedFallback())->toBeFalse();

    $second = configuredQueueRuntime(300, 0.5, 0.75);

    expect($second->visibilityWindowSeconds)->toBe(300)
        ->and($second->jobTimeoutSeconds)->toBe(290)
        ->and($second->driverTimeoutSeconds)->toBe(280)
        ->and($second->outputSecondsPerJob(QualityTier::Delivery))->toBe(112)
        ->and($second->outputSecondsPerJob(QualityTier::Intermediate))->toBe(168)
        ->and($second->driverTimeoutSeconds)->toBeLessThan($second->jobTimeoutSeconds)
        ->and($second->jobTimeoutSeconds)->toBeLessThan($second->visibilityWindowSeconds);
});

test('uses the observable fallback instead of interpreting an unknown cloud queue map', function (): void {
    config()->set('queue.default', 'cloud_test');
    config()->set('queue.connections.cloud_test', [
        'driver' => 'cloud',
        'retry_after' => 999,
        'queues' => [
            'render' => [
                'retry_after' => 888,
                'unobserved_cloud_attribute' => 777,
            ],
        ],
    ]);
    config()->set('slate.execution.visibility_window_fallback_seconds', 240);

    $settings = resolve(QueueRuntimePolicy::class)->forQueue('render');

    expect($settings->visibilityWindowSeconds)->toBe(240)
        ->and($settings->visibilityWindowSource)->toBe(VisibilityWindowSource::ConfiguredFallback)
        ->and($settings->usedFallback())->toBeTrue();
});

test('derives separate cloud runtime limits from the configured render and ingest queue windows', function (): void {
    config()->set('queue.default', 'cloud_test');
    config()->set('queue.connections.cloud_test', ['driver' => 'cloud']);
    config()->set('slate.execution.visibility_window_fallback_seconds_by_queue', [
        'default' => 180,
        'ingest' => 3600,
    ]);

    $render = resolve(QueueRuntimePolicy::class)->forQueue('default');
    $ingest = resolve(QueueRuntimePolicy::class)->forQueue('ingest');

    expect($render->queue)->toBe('default')
        ->and($render->visibilityWindowSeconds)->toBe(180)
        ->and($render->jobTimeoutSeconds)->toBe(170)
        ->and($render->driverTimeoutSeconds)->toBe(160)
        ->and($ingest->queue)->toBe('ingest')
        ->and($ingest->visibilityWindowSeconds)->toBe(3600)
        ->and($ingest->jobTimeoutSeconds)->toBe(3590)
        ->and($ingest->driverTimeoutSeconds)->toBe(3580);
});

test('reports the configured conservative fallback when the queue exposes no window', function (): void {
    config()->set('queue.default', 'policy_test');
    config()->set('queue.connections.policy_test', ['driver' => 'sqs']);
    config()->set('slate.execution.visibility_window_fallback_seconds', 240);

    $settings = resolve(QueueRuntimePolicy::class)->forQueue('render');

    expect($settings->visibilityWindowSeconds)->toBe(240)
        ->and($settings->visibilityWindowSource)->toBe(VisibilityWindowSource::ConfiguredFallback)
        ->and($settings->usedFallback())->toBeTrue();
});

test('derives both queued job timeouts and the bound driver timeout from the current window', function (): void {
    $firstSettings = configuredQueueRuntime(180);
    $run = policyFanoutRun();
    $render = new RenderFanoutOperation($run->id, $run->operations[0]);
    $finalize = new FinalizeFanoutRun($run, false);
    $single = new RenderSingleJobHybridDelivery($run);
    $timeoutProperty = new ReflectionProperty(BinaryDriver::class, 'timeoutSeconds');

    expect($render->timeout)->toBe($firstSettings->jobTimeoutSeconds)
        ->and($finalize->timeout)->toBe($firstSettings->jobTimeoutSeconds)
        ->and($single->timeout)->toBe($firstSettings->jobTimeoutSeconds)
        ->and($render->queue)->toBe('render')
        ->and($finalize->queue)->toBe('render')
        ->and($single->queue)->toBe('render')
        ->and($timeoutProperty->getValue(resolve(Driver::class)))->toBe($firstSettings->driverTimeoutSeconds);

    $secondSettings = configuredQueueRuntime(300);

    expect((new RenderFanoutOperation($run->id, $run->operations[0]))->timeout)
        ->toBe($secondSettings->jobTimeoutSeconds)
        ->and((new FinalizeFanoutRun($run, false))->timeout)->toBe($secondSettings->jobTimeoutSeconds)
        ->and((new RenderSingleJobHybridDelivery($run))->timeout)->toBe($secondSettings->jobTimeoutSeconds)
        ->and($timeoutProperty->getValue(resolve(Driver::class)))->toBe($secondSettings->driverTimeoutSeconds);
});
