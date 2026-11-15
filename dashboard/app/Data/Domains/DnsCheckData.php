<?php

declare(strict_types=1);

namespace App\Data\Domains;

use App\Enums\Domains\DnsCheckStatus;
use Spatie\LaravelData\Data;

/**
 * Outcome of a live DNS check (docs/05 §5): does `hostname` reach the edge
 * servers?
 */
final class DnsCheckData extends Data
{
    /**
     * @param  list<string>  $addresses  what the name resolves to
     * @param  list<string>  $expected  the edge servers' public addresses
     */
    public function __construct(
        public DnsCheckStatus $status,
        public string $hostname,
        public array $addresses,
        public array $expected,
        public ?string $cname,
        public string $message,
        public string $checkedAt,
        /** The proxy the records point at ("Cloudflare"), when known. */
        public ?string $proxy = null,
        /** The HTTP check reached one of our servers (proxy, NAT port forward). */
        public bool $reachedOverHttp = false,
    ) {}
}
