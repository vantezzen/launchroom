<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a retired volume (docs/05 §1.1):
 *
 *     retained → requested → deleted
 *
 * - retained: its environment/project/preview is gone; the volume stays on
 *   its server until the retention period has passed and, when its service
 *   had backups, a person confirmed the deletion.
 * - requested: rendered into the server's NodeState `deleteVolumes`;
 *   launchroomd deletes it (or reports why not and retries).
 * - deleted: launchroomd deleted it or it no longer existed.
 */
enum VolumeRetirementStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Retained = 'retained';
    case Requested = 'requested';
    case Deleted = 'deleted';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Retained => [self::Requested],
            self::Requested => [self::Deleted],
            self::Deleted => [],
        };
    }
}
