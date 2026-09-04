<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Models\RunStatus;
use App\Slate\Webhook\WebhookDestinationGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

final class DeliverRunWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public string $runId)
    {
        $this->onQueue((string) config('slate.execution.render_queue'));
    }

    /** @throws JsonException */
    public function handle(WebhookDestinationGuard $destinationGuard): void
    {
        $run = Run::query()->findOrFail($this->runId);

        if (! $run->status->isTerminal() || $run->webhook_url === null || $run->webhook_secret === null) {
            return;
        }

        $timestamp = (string) now()->unix();
        $rawBody = json_encode([
            'event' => $run->status === RunStatus::Succeeded ? 'run.succeeded' : 'run.failed',
            'run' => [
                'id' => $run->id,
                'status' => $run->status->value,
                'trust' => $run->trust->value,
                'rejection_reason' => $run->rejection_reason?->value,
            ],
            'timestamp' => (int) $timestamp,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $run->webhook_secret);
        $destination = $destinationGuard->resolve($run->webhook_url);

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Run webhook delivery requires cURL DNS pinning.');
        }

        $response = Http::connectTimeout((int) config('slate.api.webhook_connect_timeout_seconds'))
            ->timeout((int) config('slate.api.webhook_timeout_seconds'))
            ->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => [$destination->curlResolveEntry()]],
            ])
            ->withBody($rawBody, 'application/json')
            ->withHeaders([
                'X-Slate-Timestamp' => $timestamp,
                'X-Slate-Signature' => $signature,
            ])
            ->post($run->webhook_url);

        if (! $response->successful()) {
            throw new RuntimeException("Run webhook delivery returned HTTP {$response->status()}.");
        }
    }
}
