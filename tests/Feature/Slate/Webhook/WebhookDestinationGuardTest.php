<?php

declare(strict_types=1);

namespace Tests\Feature\Slate\Webhook;

use App\Slate\Webhook\HostnameResolver;
use App\Slate\Webhook\WebhookDestinationGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WebhookDestinationGuardTest extends TestCase
{
    public function test_resolves_hostname_and_builds_a_pinned_public_curl_destination(): void
    {
        $resolver = new FixedHostnameResolver(['93.184.216.34']);

        $destination = (new WebhookDestinationGuard($resolver))->resolve('https://hooks.example.com/slate');

        self::assertSame('hooks.example.com', $resolver->resolvedHostname);
        self::assertSame('93.184.216.34', $destination->address);
        self::assertSame('hooks.example.com:443:93.184.216.34', $destination->curlResolveEntry());
    }

    #[DataProvider('nonPublicAddressProvider')]
    public function test_rejects_literal_non_public_addresses(string $address): void
    {
        $guard = new WebhookDestinationGuard(new FixedHostnameResolver([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('public addresses');

        $guard->resolve('https://'.(str_contains($address, ':') ? "[{$address}]" : $address).'/hook');
    }

    public function test_rejects_hostname_when_any_resolved_address_is_private(): void
    {
        $guard = new WebhookDestinationGuard(new FixedHostnameResolver(['93.184.216.34', '10.0.0.1']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('public addresses');

        $guard->resolve('https://hooks.example.com/slate');
    }

    /** @return array<string, array{string}> */
    public static function nonPublicAddressProvider(): array
    {
        return [
            'loopback v4' => ['127.0.0.1'],
            'private v4' => ['10.0.0.1'],
            'link local metadata v4' => ['169.254.169.254'],
            'carrier grade nat v4' => ['100.64.0.1'],
            'multicast v4' => ['224.0.0.1'],
            'loopback v6' => ['::1'],
            'private v6' => ['fd00::1'],
            'link local v6' => ['fe80::1'],
        ];
    }
}

final class FixedHostnameResolver implements HostnameResolver
{
    public ?string $resolvedHostname = null;

    /** @param list<string> $addresses */
    public function __construct(private readonly array $addresses) {}

    public function resolve(string $hostname): array
    {
        $this->resolvedHostname = $hostname;

        return $this->addresses;
    }
}
