<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a service isn't fully placed right now (App\Actions\Servers\EvacuateDeadServer).
 */
enum PlacementIssueKind: string
{
    /** Degraded: its volume's server is down; never moved automatically. */
    case StatefulServerDown = 'stateful_server_down';
    /** Replicas of a dead server found no other server. */
    case Unplaceable = 'unplaceable';
}
