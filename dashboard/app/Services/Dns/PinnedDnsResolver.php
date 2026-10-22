<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\DnsResolver;

/**
 * Answers every name with the addresses a public lookup already returned,
 * so an HTTP check connects where the internet would (docs/05 §5) and never
 * resolves the name a second time through the container's resolver.
 */
final readonly class PinnedDnsResolver implements DnsResolver
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        private array $addresses,
    ) {}

    public function resolve(string $hostname): array
    {
        return $this->addresses;
    }
}
