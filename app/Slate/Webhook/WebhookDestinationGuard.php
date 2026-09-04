<?php

declare(strict_types=1);

namespace App\Slate\Webhook;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final readonly class WebhookDestinationGuard
{
    /** @var list<string> */
    private const array NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'ff00::/8',
    ];

    public function __construct(private HostnameResolver $resolver) {}

    /**
     * Resolves immediately before delivery so cURL can be pinned to the checked public address.
     * Redirects and configured/environment proxies are disabled by the caller. A transparent network
     * proxy or route that rewrites cURL's already-selected destination remains outside this process.
     */
    public function resolve(string $url): WebhookDestination
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $hostname = $parts['host'] ?? null;
        $port = $parts['port'] ?? 443;

        if ($scheme !== 'https' || ! is_string($hostname) || $hostname === '' || $port !== 443
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The webhook destination must be an HTTPS URL on port 443 without user information.');
        }

        $hostname = trim($hostname, '[]');
        $addresses = filter_var($hostname, FILTER_VALIDATE_IP) === false
            ? $this->resolver->resolve($hostname)
            : [$hostname];

        if ($addresses === []) {
            throw new InvalidArgumentException('The webhook destination hostname did not resolve.');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false || IpUtils::checkIp($address, self::NON_PUBLIC_RANGES)) {
                throw new InvalidArgumentException('The webhook destination must resolve only to public addresses.');
            }
        }

        return new WebhookDestination($url, $hostname, $addresses[0]);
    }
}
