<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use Spatie\LaravelData\Data;

/**
 * One credential field of a DNS provider's form (App\Enums\DnsProvider::fields).
 * Describes the field only; values are never part of it.
 */
final class DnsCredentialFieldData extends Data
{
    /**
     * @param  array<string, string>|null  $options  value => label (a select instead of a text field)
     */
    public function __construct(
        public string $key,
        public string $label,
        /** Write-only: never shown again once stored. */
        public bool $secret,
        /** Grammar of non-secret values (a PCRE body without delimiters). */
        public ?string $pattern = null,
        public ?array $options = null,
    ) {}
}
