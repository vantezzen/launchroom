<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\PublicDnsResolver;
use App\Services\Dns\DnsLookup;

/**
 * Public DNS for tests: every test starts with an empty zone (NXDOMAIN) so
 * nothing ever queries a real resolver. `FakeDns::set()` adds answers;
 * a name under a `*.` entry matches it like a wildcard record.
 */
final class FakeDns implements PublicDnsResolver
{
    /** @var array<string, DnsLookup> */
    private array $zone = [];

    /** @var list<string> */
    public array $queried = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(PublicDnsResolver::class, $fake);

        return $fake;
    }

    public static function current(): self
    {
        $fake = resolve(PublicDnsResolver::class);

        return $fake instanceof self ? $fake : self::install();
    }

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $cnames
     */
    public static function set(string $name, array $addresses, array $cnames = []): void
    {
        self::current()->zone[$name] = DnsLookup::found($addresses, $cnames);
    }

    public static function fail(string $name, string $error = 'timed out'): void
    {
        self::current()->zone[$name] = DnsLookup::failed($error);
    }

    public function lookup(string $hostname): DnsLookup
    {
        $this->queried[] = $hostname;

        if (isset($this->zone[$hostname])) {
            return $this->zone[$hostname];
        }

        $parent = substr($hostname, (int) strpos($hostname, '.') + 1);

        return $this->zone['*.'.$parent] ?? new DnsLookup('nxdomain');
    }
}
