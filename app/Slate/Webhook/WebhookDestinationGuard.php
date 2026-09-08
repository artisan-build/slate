<?php

declare(strict_types=1);

namespace App\Slate\Webhook;

use App\Slate\Http\DestinationGuard;
use App\Slate\Http\ResolvedDestination;

final readonly class WebhookDestinationGuard
{
    private DestinationGuard $guard;

    public function __construct(HostnameResolver $resolver)
    {
        $this->guard = new DestinationGuard($resolver);
    }

    /**
     * Resolves immediately before delivery so cURL can be pinned to the checked public address.
     * Redirects and configured/environment proxies are disabled by the caller. A transparent network
     * proxy or route that rewrites cURL's already-selected destination remains outside this process.
     */
    public function resolve(string $url): ResolvedDestination
    {
        return $this->guard->resolve($url, 'webhook destination');
    }
}
