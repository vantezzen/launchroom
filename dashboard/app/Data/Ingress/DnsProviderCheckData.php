<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use Spatie\LaravelData\Data;

/**
 * Result of checking a DNS provider token against the provider's API
 * (App\Services\Ingress\DnsProviderVerifier). Secret-free; stored in
 * `instance_settings.dns_verification`.
 */
final class DnsProviderCheckData extends Data
{
    public function __construct(
        public bool $ok,
        public string $message,
        /** The provider zone that holds the platform domain. */
        public ?string $zone,
        public string $checkedAt,
    ) {}

    /**
     * @param  array<mixed>|null  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if (! is_array($stored) || ! is_bool($stored['ok'] ?? null) || ! is_string($stored['message'] ?? null) || ! is_string($stored['checkedAt'] ?? null)) {
            return null;
        }

        return new self($stored['ok'], $stored['message'], is_string($stored['zone'] ?? null) ? $stored['zone'] : null, $stored['checkedAt']);
    }
}
