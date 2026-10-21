<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Why a backup run was taken (docs/07 `backup_runs.reason`, docs/14 §3).
 * Also written as the `reason:<value>` snapshot tag.
 */
enum BackupReason: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case PreUpgrade = 'pre_upgrade';
    case PreRestore = 'pre_restore';
    case PreDelete = 'pre_delete';
    case PreDeploy = 'pre_deploy';
    // The optional fresh backup before a server move (docs/14 §13.2).
    case PreMove = 'pre_move';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Manual => 'Manual',
            self::PreUpgrade => 'Before upgrade',
            self::PreRestore => 'Before restore',
            self::PreDelete => 'Before delete',
            self::PreDeploy => 'Before deploy',
            self::PreMove => 'Before move',
        };
    }

    public function isSafetySnapshot(): bool
    {
        return in_array($this, [self::PreUpgrade, self::PreRestore, self::PreDelete, self::PreDeploy, self::PreMove], true);
    }
}
