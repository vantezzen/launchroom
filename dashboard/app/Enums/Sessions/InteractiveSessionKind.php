<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

use App\Enums\AuditAction;
use App\Enums\Contracts\TaskBackedAccessKind;
use App\Enums\DaemonFeature;
use App\Enums\Permission;

/**
 * What an interactive session gives access to (docs/03 §11).
 */
enum InteractiveSessionKind: string implements TaskBackedAccessKind
{
    /** A shell inside one replica's container. */
    case Console = 'console';

    /** The Data tab of a managed database (browse + statements). */
    case Data = 'data';
    /** A shell in a copy of a replica's container that doesn't start the app (volumes read-only unless allowed). */
    case Recovery = 'recovery';

    public function permission(): Permission
    {
        return match ($this) {
            self::Console, self::Recovery => Permission::ExecContainers,
            self::Data => Permission::QueryDatabases,
        };
    }

    public function feature(): DaemonFeature
    {
        return match ($this) {
            self::Console => DaemonFeature::ConsoleSessions,
            self::Data => DaemonFeature::DataSessions,
            self::Recovery => DaemonFeature::RecoverySessions,
        };
    }

    public function startedAction(): AuditAction
    {
        return match ($this) {
            self::Console => AuditAction::ConsoleSessionStarted,
            self::Data => AuditAction::DataSessionStarted,
            self::Recovery => AuditAction::RecoverySessionStarted,
        };
    }

    public function endedAction(): AuditAction
    {
        return match ($this) {
            self::Console => AuditAction::ConsoleSessionEnded,
            self::Data => AuditAction::DataSessionEnded,
            self::Recovery => AuditAction::RecoverySessionEnded,
        };
    }
}
