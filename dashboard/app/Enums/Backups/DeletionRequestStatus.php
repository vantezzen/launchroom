<?php

declare(strict_types=1);

namespace App\Enums\Backups;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a deletion that waits for its final backups (docs/14 §9.13):
 *
 *     backing_up → ready → completed
 *          ↓  ↑ (retry)
 *        blocked → overridden → completed
 *
 * - backing_up: final backups (database dumps, volume snapshots) are running;
 * - ready: all succeeded; the deletion runs (environments and projects by
 *   the backup tick, staged service removals with the next Deploy);
 * - blocked: a final backup failed or could not start; nothing was deleted;
 * - overridden: an owner/admin chose "delete without final backup" (recent
 *   password, audited);
 * - cancelled: the removal was discarded or the subject is gone otherwise.
 */
enum DeletionRequestStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case BackingUp = 'backing_up';
    case Blocked = 'blocked';
    case Ready = 'ready';
    case Overridden = 'overridden';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::BackingUp => [self::Ready, self::Blocked, self::Overridden, self::Cancelled],
            self::Blocked => [self::BackingUp, self::Overridden, self::Cancelled],
            self::Ready, self::Overridden => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    /**
     * The deletion may proceed.
     */
    public function allowsDeletion(): bool
    {
        return in_array($this, [self::Ready, self::Overridden], true);
    }
}
