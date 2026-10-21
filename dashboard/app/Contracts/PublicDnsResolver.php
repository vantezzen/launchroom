<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Dns\DnsLookup;
use App\Services\Dns\UdpDnsResolver;
use Illuminate\Container\Attributes\Bind;

/**
 * Looks a hostname up the way the internet sees it (docs/05 §5 "live
 * verification"): A/AAAA records and the CNAME chain, from public recursive
 * resolvers, with short timeouts. DNS only: nothing ever connects to the
 * addresses it returns, so user-supplied names can't reach internal services.
 */
#[Bind(UdpDnsResolver::class)]
interface PublicDnsResolver
{
    public function lookup(string $hostname): DnsLookup;
}
