<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * docs/05 §5: generated (platform wildcard, sslip.io or *.localhost), custom
 * (user-owned, on-demand TLS) and per-deployment URLs.
 */
enum DomainKind: string
{
    case Generated = 'generated';
    case Custom = 'custom';
    case Deployment = 'deployment';
}
