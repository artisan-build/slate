<?php

declare(strict_types=1);

namespace App\Slate\Webhook;

interface HostnameResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
