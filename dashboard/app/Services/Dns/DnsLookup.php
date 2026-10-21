<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Result of a public DNS lookup: `ok` (records found), `nxdomain` (the name
 * doesn't exist), `empty` (exists, no A/AAAA) or `error` (no resolver
 * answered in time; `error` says why).
 */
final readonly class DnsLookup
{
    /**
     * @param  list<string>  $addresses  A and AAAA values
     * @param  list<string>  $cnames  the CNAME chain, in order
     */
    public function __construct(
        public string $status,
        public array $addresses = [],
        public array $cnames = [],
        public ?string $error = null,
    ) {}

    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $cnames
     */
    public static function found(array $addresses, array $cnames = []): self
    {
        return new self($addresses === [] ? 'empty' : 'ok', $addresses, $cnames);
    }

    public static function failed(string $error): self
    {
        return new self('error', error: $error);
    }
}
