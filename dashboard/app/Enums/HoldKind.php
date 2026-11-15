<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What holds a service (docs/14 §5.1, §12.5, §13.2): its workloads leave
 * their servers' desired state until the hold ends.
 */
enum HoldKind: string
{
    case Restore = 'restore';
    case Import = 'import';
    case Move = 'move';

    /**
     * Whether the hold wins over the service's deployments (docs/04 §6
     * "Imports and restores win"): a started one is cancelled, a queued one
     * waits. A server move redeploys the services it holds itself (it owns
     * their deployments while it runs), so it leaves them alone.
     */
    public function blocksDeployments(): bool
    {
        return $this !== self::Move;
    }
}
