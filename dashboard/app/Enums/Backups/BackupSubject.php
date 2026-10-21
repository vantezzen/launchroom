<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * What a schedule or run covers (docs/14 §1). A volume run covers one
 * volume of a service that is not a database (databases are dumped). A
 * base backup is a physical Postgres backup, the starting point of
 * point-in-time recovery (docs/14 §10); runs, restores and verifications
 * use it, schedules don't (the WAL archive carries its own schedule).
 */
enum BackupSubject: string
{
    case Database = 'database';
    case Volume = 'volume';
    case ControlPlane = 'control_plane';
    case BaseBackup = 'base_backup';

    /**
     * The `kind:` snapshot tag value (docs/14 §2).
     */
    public function tag(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Volume => 'volume',
            self::ControlPlane => 'control-plane',
            self::BaseBackup => 'base_backup',
        };
    }
}
