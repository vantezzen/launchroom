<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * The state of one row of the Backups page, in the order rows are sorted
 * (what needs the user first).
 */
enum BackupItemState: string
{
    case Failed = 'failed';
    case Overdue = 'overdue';
    case Running = 'running';
    /** Created, waiting for its server (one heavy task per server, docs/14 §7). */
    case Queued = 'queued';
    /** Never backed up; the first one is scheduled. */
    case Waiting = 'waiting';
    /** The service is stopped (D68): its backups wait until it runs again. */
    case Paused = 'paused';
    case Ok = 'ok';

    public function rank(): int
    {
        return match ($this) {
            self::Failed => 0,
            self::Overdue => 1,
            self::Running => 2,
            self::Queued => 3,
            self::Waiting => 4,
            self::Paused => 5,
            self::Ok => 6,
        };
    }
}
