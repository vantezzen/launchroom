<?php

declare(strict_types=1);

namespace App\Enums\Updates;

use App\Enums\Attributes\SharedFacts;
use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\StatusTone;

/**
 * Lifecycle of a control-plane update (docs/11 §4, docs/07 `control_plane_updates`).
 */
#[SharedFacts('isFailed')]
enum ControlPlaneUpdateStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    // Pre-upgrade control-plane backup (docs/14 §3).
    case BackingUp = 'backing_up';
    // The self_update task (scope control_plane) is queued or running.
    case Updating = 'updating';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // The new release was installed but did not become healthy; the previous
    // compose files and database snapshot were restored.
    case RolledBack = 'rolled_back';
    // Stopped by the owner (or given up by launchroom) before the self_update
    // task started: nothing was changed (docs/11 §4.3).
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::BackingUp, self::Updating, self::Failed, self::Cancelled],
            self::BackingUp => [self::Updating, self::Failed, self::Cancelled],
            // Cancelled only while launchroomd hasn't taken the task yet.
            self::Updating => [self::Succeeded, self::Failed, self::RolledBack, self::Cancelled],
            self::Succeeded, self::Failed, self::RolledBack, self::Cancelled => [],
        };
    }

    /** Finished without the new release running: failed, or restored to the previous one. */
    public function isFailed(): bool
    {
        return $this === self::Failed || $this === self::RolledBack;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::BackingUp => 'Backing up',
            self::Updating => 'Updating',
            self::Succeeded => 'Updated',
            self::Failed => 'Failed',
            self::RolledBack => 'Rolled back',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending, self::BackingUp, self::Updating => StatusTone::Progress,
            self::Succeeded => StatusTone::Active,
            self::Failed => StatusTone::Failed,
            self::RolledBack => StatusTone::Warning,
            self::Cancelled => StatusTone::Idle,
        };
    }
}
