<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a domain's last certificate attempt failed (report.json
 * CertificateStatus.failureKind, Go `state.CertFailureKind`). Read leniently.
 */
enum CertFailureKind: string
{
    /** The certificate authority's rate limit (`retryAfter`). */
    case RateLimited = 'rate_limited';
    /** The certificate authority reached another address than this edge. */
    case DnsMismatch = 'dns_mismatch';
    case Caa = 'caa';
    case Timeout = 'timeout';
    /** The DNS provider's API refused the challenge record. */
    case DnsProvider = 'dns_provider';
    case Other = 'other';
}
