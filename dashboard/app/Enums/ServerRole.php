<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a server is used for (docs/02, docs/03 §1). A server has a set of roles.
 */
enum ServerRole: string
{
    /** Runs Caddy and receives public traffic. */
    case Edge = 'edge';

    /** Runs app and database workloads. */
    case Runtime = 'runtime';

    /** Runs BuildKit and leases build tasks. */
    case Build = 'build';

    /** Hosts the registry (Zot). */
    case Registry = 'registry';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
