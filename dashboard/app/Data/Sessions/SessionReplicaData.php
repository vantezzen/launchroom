<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Models\Placement;
use Spatie\LaravelData\Data;

/**
 * One replica a session can target (the replica picker).
 */
final class SessionReplicaData extends Data
{
    public function __construct(
        public string $workload,
        public int $slot,
        /** "Replica 1" (slots are 0-based). */
        public string $label,
        public string $serverId,
        public string $serverName,
        /** WorkloadPhase value, e.g. `healthy`. */
        public string $phase,
    ) {}

    public static function fromPlacement(Placement $placement): self
    {
        return new self(
            $placement->workload_id,
            $placement->slot,
            'Replica '.($placement->slot + 1),
            $placement->server->public_id,
            $placement->server->name,
            $placement->phase->value,
        );
    }
}
