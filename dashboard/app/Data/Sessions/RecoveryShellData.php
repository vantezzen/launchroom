<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Spatie\LaravelData\Data;

/**
 * The Console tab's recovery shell (docs/08 §15, OD9): offered while a
 * replica's container won't stay up. A copy of it starts without the app,
 * volumes read-only unless the user allows changes.
 */
final class RecoveryShellData extends Data
{
    /**
     * @param  list<SessionReplicaData>  $replicas  replicas whose container won't stay up
     * @param  list<SessionHistoryItemData>  $sessions
     */
    public function __construct(
        public bool $available,
        /** Why a recovery shell can't be opened right now (one sentence), or null. */
        public ?string $unavailableReason,
        public array $replicas,
        /** Allowing changes is refused while a container of this service with data still runs. */
        public bool $changesNeedStop,
        public array $sessions,
    ) {}
}
