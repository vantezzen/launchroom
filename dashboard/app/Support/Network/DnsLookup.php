<?php

declare(strict_types=1);

namespace App\Support\Network;

use App\Enums\Domains\DnsLookupStatus;

/**
 * Result of a public DNS lookup; `error` says why a lookup failed.
 */
final readonly class DnsLookup
{
    /**
     * @param  list<string>  $addresses  A and AAAA values
     * @param  list<string>  $cnames  the CNAME chain, in order
     */
    public function __construct(
        public DnsLookupStatus $status,
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
        return new self($addresses === [] ? DnsLookupStatus::Empty : DnsLookupStatus::Ok, $addresses, $cnames);
    }

    public static function failed(string $error): self
    {
        return new self(DnsLookupStatus::Error, error: $error);
    }
}
