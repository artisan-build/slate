<?php

declare(strict_types=1);

namespace App\Slate\Http;

final readonly class ResolvedDestination
{
    public function __construct(
        public string $url,
        public string $hostname,
        public string $address,
        public int $port = 443,
    ) {}

    public function curlResolveEntry(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->hostname}:{$this->port}:{$address}";
    }
}
