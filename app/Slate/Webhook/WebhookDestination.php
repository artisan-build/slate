<?php

declare(strict_types=1);

namespace App\Slate\Webhook;

final readonly class WebhookDestination
{
    public function __construct(
        public string $url,
        public string $hostname,
        public string $address,
    ) {}

    public function curlResolveEntry(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->hostname}:443:{$address}";
    }
}
