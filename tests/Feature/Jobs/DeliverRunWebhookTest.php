<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DeliverRunWebhook;
use App\Models\Run;
use App\Models\RunStatus;
use App\Models\RunTrust;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Webhook\HostnameResolver;
use App\Slate\Webhook\WebhookDestinationGuard;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class DeliverRunWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_terminal_transition_queues_one_webhook_without_serializing_its_secret(): void
    {
        Queue::fake([DeliverRunWebhook::class]);
        $run = $this->webhookRun();

        $run->transitionTo(RunStatus::Running);
        $run->transitionTo(RunStatus::Succeeded);
        $run->transitionTo(RunStatus::Succeeded);

        Queue::assertPushed(DeliverRunWebhook::class, 1);
        Queue::assertPushed(DeliverRunWebhook::class, function (DeliverRunWebhook $job) use ($run): bool {
            self::assertSame($run->id, $job->runId);
            self::assertStringNotContainsString('webhook-test-secret', serialize($job));

            return $job->queue === 'default';
        });
    }

    public function test_delivery_posts_signed_terminal_status_without_result_bytes(): void
    {
        $this->travelTo('2026-09-05 14:00:00');
        Queue::fake([DeliverRunWebhook::class]);
        Http::preventStrayRequests();
        Http::fake([
            'hooks.example.test/slate' => Http::response(status: 204),
        ]);
        app()->instance(HostnameResolver::class, new WebhookTestHostnameResolver(['93.184.216.34']));
        $run = $this->webhookRun();
        $run->transitionTo(RunStatus::Running);
        $run->reject(InputRejectionReason::ReferencedMedia);
        $job = new DeliverRunWebhook($run->id);

        $job->handle(resolve(WebhookDestinationGuard::class));

        Http::assertSent(function (Request $request) use ($run): bool {
            $timestamp = (string) $request->header('X-Slate-Timestamp')[0];
            $signature = (string) $request->header('X-Slate-Signature')[0];
            $rawBody = $request->body();
            $expectedBody = json_encode([
                'event' => 'run.failed',
                'run' => [
                    'id' => $run->id,
                    'status' => 'failed',
                    'trust' => 'trusted-origin',
                    'rejection_reason' => 'referenced_media',
                ],
                'timestamp' => 1_788_616_800,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            self::assertSame('https://hooks.example.test/slate', $request->url());
            self::assertSame('1788616800', $timestamp);
            self::assertSame($expectedBody, $rawBody);
            self::assertSame(hash_hmac('sha256', $timestamp.'.'.$rawBody, 'webhook-test-secret'), $signature);

            return true;
        });
    }

    public function test_delivery_rechecks_dns_and_refuses_a_rebound_private_address(): void
    {
        Queue::fake([DeliverRunWebhook::class]);
        Http::preventStrayRequests();
        Http::fake();
        app()->instance(HostnameResolver::class, new WebhookTestHostnameResolver(['10.0.0.1']));
        $run = $this->webhookRun();
        $run->transitionTo(RunStatus::Running);
        $run->transitionTo(RunStatus::Succeeded);

        $this->expectException(InvalidArgumentException::class);

        try {
            (new DeliverRunWebhook($run->id))->handle(resolve(WebhookDestinationGuard::class));
        } finally {
            Http::assertNothingSent();
            self::assertSame(RunStatus::Succeeded, $run->fresh()->status);
        }
    }

    private function webhookRun(): Run
    {
        $factoryRun = Run::factory()->make();

        return Run::createWithRecipe(
            $factoryRun->id,
            $factoryRun->recipe(),
            new Subject(SubjectType::Application, 'webhook-tests'),
            RunTrust::TrustedOrigin,
            'https://hooks.example.test/slate',
            'webhook-test-secret',
        );
    }
}

final readonly class WebhookTestHostnameResolver implements HostnameResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses) {}

    public function resolve(string $hostname): array
    {
        return $this->addresses;
    }
}
