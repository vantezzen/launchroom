<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * The web firewall's state at an edge (Report.ingress.waf, docs/03 §13.4).
 */
enum WafEdgeState: string
{
    /** At least one route runs it. */
    case On = 'on';
    /** No route asks for it. */
    case Off = 'off';
    /** Caddy refused the config with the firewall and serves the routes without it. */
    case Error = 'error';
    /** The pinned Caddy has no firewall module. */
    case Unsupported = 'unsupported';
}
