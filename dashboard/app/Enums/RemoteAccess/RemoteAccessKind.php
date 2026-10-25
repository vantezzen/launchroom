<?php

declare(strict_types=1);

namespace App\Enums\RemoteAccess;

use App\Enums\AuditAction;
use App\Enums\DaemonFeature;
use App\Enums\Permission;

/**
 * What a temporary access opens (D61, D67, docs/03 §12).
 */
enum RemoteAccessKind: string
{
    /** SSH and SFTP into one replica's container. */
    case Ssh = 'ssh';

    /** A temporary role on a managed database, for the user's own client. */
    case Database = 'database';

    /** SFTP into one volume, through a helper container that mounts only it (D67). */
    case Volume = 'volume';

    /** The same permission as the Console and Data tabs (docs/09 R33); a volume's files are the console's. */
    public function permission(): Permission
    {
        return match ($this) {
            self::Ssh, self::Volume => Permission::ExecContainers,
            self::Database => Permission::QueryDatabases,
        };
    }

    public function feature(): DaemonFeature
    {
        return match ($this) {
            self::Ssh => DaemonFeature::RemoteAccessSsh,
            self::Database => DaemonFeature::RemoteAccessDatabase,
            self::Volume => DaemonFeature::RemoteAccessVolume,
        };
    }

    public function startedAction(): AuditAction
    {
        return match ($this) {
            self::Ssh => AuditAction::SshAccessStarted,
            self::Database => AuditAction::DatabaseAccessStarted,
            self::Volume => AuditAction::VolumeAccessStarted,
        };
    }

    public function endedAction(): AuditAction
    {
        return match ($this) {
            self::Ssh => AuditAction::SshAccessEnded,
            self::Database => AuditAction::DatabaseAccessEnded,
            self::Volume => AuditAction::VolumeAccessEnded,
        };
    }

    /** Signs in over SSH with keys or a generated password (SSH and volume access). */
    public function usesSshKeys(): bool
    {
        return $this !== self::Database;
    }
}
