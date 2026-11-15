<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Network\DnsLookup;

/**
 * Looks a hostname up the way the internet sees it (docs/05 §5 "live
 * verification"): A/AAAA records and the CNAME chain, from public recursive
 * resolvers, with short timeouts. Only EdgeReachability connects to the
 * addresses it returns, through SafeHttpClient (public addresses only), so
 * user-supplied names can't reach internal services.
 */
interface PublicDnsResolver
{
    public function lookup(string $hostname): DnsLookup;
}
