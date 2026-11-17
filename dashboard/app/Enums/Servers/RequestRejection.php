<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * Why the dashboard refused a request a server signed correctly
 * (`servers.last_rejection`, App\Http\Middleware\AuthenticateDaemon).
 * Requests with a bad signature are never recorded: anyone can send them.
 */
enum RequestRejection: string
{
    /** Its clock is off by more than the allowed skew (60 s). */
    case ClockSkew = 'clock_skew';
    /** Its key was revoked (removed or replaced server). */
    case Revoked = 'revoked';
    /** Its client certificate is unknown, expired, revoked or another server's. */
    case Certificate = 'certificate';
    /** Client certificates are required and it sent none. */
    case CertificateRequired = 'certificate_required';
}
