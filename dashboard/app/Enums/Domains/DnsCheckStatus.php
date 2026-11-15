<?php

declare(strict_types=1);

namespace App\Enums\Domains;

use App\Enums\StatusTone;

/**
 * Outcome of a live DNS check (docs/05 §5). Stored in `domains.verification`
 * and `dashboard_domain_changes.dns`.
 */
enum DnsCheckStatus: string
{
    /** Points at the edge servers, or reaches them through a proxy (checked over HTTP). */
    case Ok = 'ok';
    /** Points at a known proxy such as Cloudflare; the HTTP check couldn't confirm it forwards to us. */
    case Proxied = 'proxied';
    /** Points elsewhere, or only partly at us. */
    case Mismatch = 'mismatch';
    case Missing = 'missing';
    /** No edge address to compare with. */
    case Unknown = 'unknown';
    /** No resolver answered. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'DNS verified',
            self::Proxied => 'Behind a proxy, not confirmed',
            self::Mismatch => 'DNS points elsewhere',
            self::Missing => 'No DNS record yet',
            self::Unknown => 'Can’t compare yet',
            self::Error => 'DNS check failed',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Ok => StatusTone::Active,
            self::Mismatch => StatusTone::Failed,
            self::Proxied, self::Missing, self::Unknown, self::Error => StatusTone::Warning,
        };
    }
}
