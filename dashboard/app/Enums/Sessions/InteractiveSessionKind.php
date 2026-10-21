<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

use App\Enums\AuditAction;
use App\Enums\DaemonFeature;
use App\Enums\Permission;

/**
 * What an interactive session gives access to (docs/03 §11).
 */
enum InteractiveSessionKind: string
{
    /** A shell inside one replica's container. */
    case Console = 'console';

    /** The Data tab of a managed database (browse + statements). */
    case Data = 'data';

    public function permission(): Permission
    {
        return match ($this) {
            self::Console => Permission::ExecContainers,
            self::Data => Permission::QueryDatabases,
        };
    }

    /** What the replica's launchroomd must announce (its local policy allows the kind). */
    public function feature(): DaemonFeature
    {
        return match ($this) {
            self::Console => DaemonFeature::ConsoleSessions,
            self::Data => DaemonFeature::DataSessions,
        };
    }

    public function startedAction(): AuditAction
    {
        return match ($this) {
            self::Console => AuditAction::ConsoleSessionStarted,
            self::Data => AuditAction::DataSessionStarted,
        };
    }

    public function endedAction(): AuditAction
    {
        return match ($this) {
            self::Console => AuditAction::ConsoleSessionEnded,
            self::Data => AuditAction::DataSessionEnded,
        };
    }
}
