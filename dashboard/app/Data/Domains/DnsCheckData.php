<?php

declare(strict_types=1);

namespace App\Data\Domains;

use Spatie\LaravelData\Data;

/**
 * Outcome of a live DNS check (docs/05 §5): does `hostname` point at the
 * edge servers? `ok`, `mismatch` (points elsewhere, or only partly at us),
 * `missing` (no record yet), `unknown` (no edge address to compare with) or
 * `error` (no resolver answered).
 */
final class DnsCheckData extends Data
{
    /**
     * @param  list<string>  $addresses  what the name resolves to
     * @param  list<string>  $expected  the edge servers' public addresses
     */
    public function __construct(
        public string $status,
        public string $hostname,
        public array $addresses,
        public array $expected,
        public ?string $cname,
        public string $message,
        public string $checkedAt,
    ) {}

    /**
     * @param  array<mixed>|null  $stored  `domains.verification`
     */
    public static function fromStored(?array $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['status'] ?? null) || ! is_string($stored['checkedAt'] ?? null)) {
            return null;
        }

        $strings = static fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, is_string(...))) : [];

        return new self(
            status: $stored['status'],
            hostname: is_string($stored['hostname'] ?? null) ? $stored['hostname'] : '',
            addresses: $strings($stored['addresses'] ?? null),
            expected: $strings($stored['expected'] ?? null),
            cname: is_string($stored['cname'] ?? null) ? $stored['cname'] : null,
            message: is_string($stored['message'] ?? null) ? $stored['message'] : '',
            checkedAt: $stored['checkedAt'],
        );
    }
}
