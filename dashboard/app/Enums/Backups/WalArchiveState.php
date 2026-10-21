<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * WAL shipping as launchroomd reports it (protocol WalArchiveState, docs/14 §10.2).
 */
enum WalArchiveState: string
{
    /** The last cycle shipped (or had nothing to ship). */
    case Active = 'active';
    /** The database has no running container (yet). */
    case Waiting = 'waiting';
    /** The last cycle failed; segments stay until the next one ships them. */
    case Failing = 'failing';
}
