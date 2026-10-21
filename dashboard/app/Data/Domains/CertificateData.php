<?php

declare(strict_types=1);

namespace App\Data\Domains;

use App\Enums\CertStatus;
use App\Models\Domain;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A domain's certificate as the edges report it (docs/05 §5), with the
 * expiry warning: Caddy renews when a third of the lifetime is left (30 days
 * of 90, 15 of 45; Let's Encrypt moves to 64 days in Feb 2027 and 45 days in
 * Feb 2028). `expiring`: renewal is overdue (a sixth of the lifetime, at
 * least 3 days, left); `expired`: past its end.
 */
final class CertificateData extends Data
{
    public function __construct(
        public CertStatus $status,
        public ?string $issuer,
        public ?CarbonInterface $notBefore,
        public ?CarbonInterface $expiresAt,
        public bool $wildcard,
        public ?string $error,
        public ?CarbonInterface $errorAt,
        public ?CarbonInterface $checkedAt,
        /** `expiring`, `expired` or null. */
        public ?string $warning,
    ) {}

    public static function fromDomain(Domain $domain): ?self
    {
        if (! $domain->cert_status instanceof CertStatus) {
            return null;
        }

        return new self(
            status: $domain->cert_status,
            issuer: $domain->cert_issuer,
            notBefore: $domain->cert_not_before,
            expiresAt: $domain->cert_expires_at,
            wildcard: $domain->cert_wildcard,
            error: $domain->cert_error,
            errorAt: $domain->cert_error_at,
            checkedAt: $domain->cert_checked_at,
            warning: self::warning($domain->cert_not_before, $domain->cert_expires_at),
        );
    }

    public static function warning(?CarbonInterface $notBefore, ?CarbonInterface $expiresAt): ?string
    {
        if (! $expiresAt instanceof CarbonInterface) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        $left = now()->diffInSeconds($expiresAt);
        $lifetime = $notBefore instanceof CarbonInterface ? max(86400, $notBefore->diffInSeconds($expiresAt)) : 90 * 86400;
        // Half of Caddy's renewal window (a third of the lifetime) has passed
        // without a renewal: 15 days of 90, ~11 of 64, 7.5 of 45; at least 3 days.
        $threshold = max($lifetime / 6, 3 * 86400);

        return $left <= $threshold ? 'expiring' : null;
    }
}
