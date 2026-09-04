<?php

declare(strict_types=1);

namespace App\Slate\Webhook;

final readonly class SystemHostnameResolver implements HostnameResolver
{
    public function resolve(string $hostname): array
    {
        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
