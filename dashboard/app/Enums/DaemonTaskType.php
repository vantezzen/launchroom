<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Task types of protocol v1 (protocol/schema/v1/task.json).
 */
enum DaemonTaskType: string
{
    case Build = 'build';
    case Plan = 'plan';
    case RunOnce = 'run_once';
    case Backup = 'backup';
    case Restore = 'restore';
    case BackupMaintenance = 'backup_maintenance';
    case Logs = 'logs';
    case SelfUpdate = 'self_update';
    case BackupSnapshots = 'backup_snapshots';
    /** Restore a snapshot into a throwaway container and check it (docs/14 §10.4). */
    case VerifyRestore = 'verify_restore';
    /** Interactive console / database data session (docs/03 §11). */
    case Session = 'session';
    /** Import a dump or a connection URL into a managed database (D55, docs/14 §12). */
    case DbImport = 'db_import';
    /** Extract an archive into a service volume (D55). */
    case VolumeImport = 'volume_import';
    /** A database's data in its native format, for download (D55). */
    case DbExport = 'db_export';
    /** A volume's files as a .tar.gz, for download (D55). */
    case VolumeExport = 'volume_export';
}
