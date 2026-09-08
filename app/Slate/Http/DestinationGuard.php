<?php

declare(strict_types=1);

namespace App\Slate\Http;

use App\Slate\Webhook\HostnameResolver;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final readonly class DestinationGuard
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
     * Resolves immediately before a request sequence so cURL can be pinned to the checked public
     * address. Callers must disable redirects and configured/environment proxies on every request.
     * A transparent network proxy or route that rewrites cURL's selected destination remains outside
     * this process.
     */
    public function resolve(string $url, string $description = 'destination'): ResolvedDestination
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $hostname = $parts['host'] ?? null;
        $port = $parts['port'] ?? 443;

        if ($scheme !== 'https' || ! is_string($hostname) || $hostname === '' || $port !== 443
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException("The {$description} must be an HTTPS URL on port 443 without user information.");
        }

        $hostname = trim($hostname, '[]');
        $addresses = filter_var($hostname, FILTER_VALIDATE_IP) === false
            ? $this->resolver->resolve($hostname)
            : [$hostname];

        if ($addresses === []) {
            throw new InvalidArgumentException("The {$description} hostname did not resolve.");
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false || IpUtils::checkIp($address, self::NON_PUBLIC_RANGES)) {
                throw new InvalidArgumentException("The {$description} must resolve only to public addresses.");
            }
        }

        return new ResolvedDestination($url, $hostname, $addresses[0], $port);
    }
}
