<?php

declare(strict_types=1);

namespace App\Data\Domains;

use App\Enums\CertFailureKind;
use App\Enums\CertStatus;
use App\Enums\Domains\DnsCheckStatus;
use App\Models\Domain;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A domain's certificate as the edges report it (docs/05 §5), with the
 * expiry warning: Caddy renews when a third of the lifetime is left (30 days
 * of 90, 15 of 45; Let's Encrypt moves to 64 days in Feb 2027 and 45 days in
 * Feb 2028). `expiring`: renewal is overdue (a sixth of the lifetime, at
 * least 3 days, left); `expired`: past its end.
 *
 * When issuing fails: why (`failureKind`), when the certificate authority
 * allows the next try (`retryAfter`, its rate limit) and `problem`, what to
 * do in a sentence. "Retry now" is offered once `retryAfter` has passed.
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
        public ?CertFailureKind $failureKind = null,
        public ?CarbonInterface $retryAfter = null,
        public ?string $problem = null,
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
            failureKind: $domain->cert_status === CertStatus::Issued ? null : $domain->cert_failure_kind,
            retryAfter: $domain->cert_retry_after?->isFuture() === true ? $domain->cert_retry_after : null,
            problem: self::problem($domain),
        );
    }

    /**
     * What went wrong with the last attempt and what to do, in a sentence;
     * null when the certificate is issued or nothing failed yet.
     */
    public static function problem(Domain $domain): ?string
    {
        if ($domain->cert_status === CertStatus::Issued || ($domain->cert_failure_kind === null && $domain->cert_error === null)) {
            return null;
        }

        $host = $domain->hostname;
        $until = $domain->cert_retry_after?->isFuture() === true ? $domain->cert_retry_after->format('j M H:i').' UTC' : null;
        $dns = $domain->verification;

        return match ($domain->cert_failure_kind) {
            CertFailureKind::RateLimited => $until !== null
                ? "Let’s Encrypt limits new certificates for {$host} until {$until}. launchroom tries again then."
                : "Let’s Encrypt limited new certificates for {$host}. launchroom tries again when the limit ends.",
            CertFailureKind::DnsMismatch => $dns !== null && $dns->status === DnsCheckStatus::Mismatch && $dns->addresses !== []
                ? "{$host} points to ".implode(', ', array_slice($dns->addresses, 0, 3)).', which isn’t one of your servers. Point its A and AAAA records at '.implode(', ', array_slice($dns->expected, 0, 3)).'.'
                : "Let’s Encrypt reached another machine than your servers for {$host}. Check its A and AAAA records.",
            CertFailureKind::Caa => "A CAA record of {$host} doesn’t allow Let’s Encrypt. Add `0 issue \"letsencrypt.org\"` to it or remove the record.",
            CertFailureKind::Timeout => "Let’s Encrypt couldn’t reach your server for {$host} on port 80 or 443. Check the firewall of the server and of your hosting provider.",
            CertFailureKind::DnsProvider => 'The DNS provider refused the challenge record. Check the API token in Settings → Network → Certificates.',
            default => $domain->cert_error !== null ? mb_substr($domain->cert_error, 0, 300) : null,
        };
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
