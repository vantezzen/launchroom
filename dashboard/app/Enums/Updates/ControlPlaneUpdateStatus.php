<?php

declare(strict_types=1);

namespace App\Enums\Updates;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a control-plane update (docs/11 §4, docs/07 `control_plane_updates`).
 */
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

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::BackingUp, self::Updating, self::Failed],
            self::BackingUp => [self::Updating, self::Failed],
            self::Updating => [self::Succeeded, self::Failed, self::RolledBack],
            self::Succeeded, self::Failed, self::RolledBack => [],
        };
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
        };
    }
}
