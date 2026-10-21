<?php

declare(strict_types=1);

namespace App\Enums\Moves;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * One service of a server move (docs/14 §13.2).
 *
 * Stateless: pending → starting (redeployed, the source drains) → done.
 *
 * Stateful (dump, files): pending → building (rebuilt for the target's
 * platform while it still runs, only on an architecture change) →
 * backing_up (optional fresh backup) → stopping (the apps that write to it
 * stop) → exporting (copied off the source) → **cutover** → preparing (its
 * volumes, or the empty database, exist on the target) → importing →
 * [files: starting] → done.
 *
 * Until it is done, a failure or a cancel after the cutover rolls it back
 * (rolling_back: it starts on the source again, whose volume was never
 * touched) → failed (to retry) or rolled_back (cancelled). Before the
 * cutover nothing changed on the source: failed / cancelled directly.
 */
enum MoveItemStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Building = 'building';
    case BackingUp = 'backing_up';
    case Stopping = 'stopping';
    case Exporting = 'exporting';
    case Preparing = 'preparing';
    case Importing = 'importing';
    case Starting = 'starting';
    case Done = 'done';
    case Failed = 'failed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Building, self::BackingUp, self::Stopping, self::Starting, self::Done, self::Failed, self::Cancelled, self::Skipped],
            self::Building => [self::BackingUp, self::Stopping, self::Failed, self::Cancelled, self::Skipped],
            self::BackingUp => [self::Stopping, self::Failed, self::Cancelled, self::Skipped],
            self::Stopping => [self::Exporting, self::Failed, self::Cancelled, self::Skipped],
            self::Exporting => [self::Preparing, self::Failed, self::Cancelled, self::Skipped],
            self::Preparing => [self::Importing, self::RollingBack, self::Skipped],
            self::Importing => [self::Starting, self::Done, self::RollingBack, self::Skipped],
            self::Starting => [self::Done, self::Failed, self::RollingBack, self::Cancelled, self::Skipped],
            self::RollingBack => [self::Failed, self::RolledBack, self::Skipped],
            self::Failed => [self::Pending, self::Cancelled, self::Skipped],
            self::Done, self::RolledBack, self::Cancelled, self::Skipped => [],
        };
    }

    /**
     * Finished for the move: it runs on the target, or there is nothing left to move.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Done, self::Skipped], true);
    }

    /**
     * Past the cutover and not done: its volumes are on the target, its
     * source copy is still intact, so it can go back.
     */
    public function isPastCutover(): bool
    {
        return in_array($this, [self::Preparing, self::Importing, self::Starting], true);
    }

    /**
     * The services that write to it are held (they leave their servers'
     * desired state) in these steps.
     */
    public function holdsWriters(): bool
    {
        return in_array($this, [self::Stopping, self::Exporting, self::Preparing, self::Importing, self::Starting, self::RollingBack], true);
    }

    /**
     * Still moving (the move waits for it).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Building, self::BackingUp, self::Stopping, self::Exporting, self::Preparing, self::Importing, self::Starting, self::RollingBack], true);
    }
}
