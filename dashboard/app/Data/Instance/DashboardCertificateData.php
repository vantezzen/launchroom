<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * The main server's certificate report for a dashboard domain being set up.
 */
final class DashboardCertificateData extends Data
{
    public function __construct(
        /** `issued`, `pending` or `failed`. */
        public string $state,
        public ?string $issuer,
        public ?string $notAfter,
        public ?string $lastError,
    ) {}

    /**
     * @param  array<mixed>|null  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if (! is_array($stored) || ! is_string($stored['state'] ?? null)) {
            return null;
        }

        $string = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self($stored['state'], $string($stored['issuer'] ?? null), $string($stored['notAfter'] ?? null), $string($stored['lastError'] ?? null));
    }
}
