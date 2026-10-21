<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Backup health of a database or the control plane (docs/14 §3 "Visibility").
 */
enum BackupHealth: string
{
    /** A recent successful backup. */
    case Ok = 'ok';
    /** The last success is older than launchroom.backups.stale_after_hours. */
    case Stale = 'stale';
    /** The latest finished run failed. */
    case Failing = 'failing';
    case Running = 'running';
    /** Scheduled, no run yet. */
    case None = 'none';
    /** No destination: backups are off. */
    case Unconfigured = 'unconfigured';
}
