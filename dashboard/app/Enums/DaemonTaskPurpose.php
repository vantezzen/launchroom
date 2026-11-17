<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\Sessions\InteractiveSessionKind;

/**
 * Why a task exists, when its type alone doesn't say (`daemon_tasks.purpose`):
 * the owner of a `run_once`, `self_update`, `session`, `access` or `logs`
 * task finds its task by it.
 */
enum DaemonTaskPurpose: string
{
    /** run_once: the release command before rollout (docs/04 §13). */
    case Release = 'release';
    /** run_once: the seed command after an instance's first deploy. */
    case Seed = 'seed';
    /** run_once: the sanitize command of a preview's cloned database (docs/06 §5). */
    case Sanitize = 'sanitize';
    /** run_once: the start check of a generated Dockerfile (docs/20 §5). */
    case DockerfileCheck = 'dockerfile_check';
    /** self_update of the control plane (docs/11 §4). */
    case ControlPlaneUpdate = 'cp_update';
    /** self_update of one server of a fleet rollout. */
    case FleetUpdate = 'fleet_update';
    case ConsoleSession = 'console';
    case DataSession = 'data';
    case RecoverySession = 'recovery';
    case AccessSsh = 'access_ssh';
    case AccessDatabase = 'access_database';
    case AccessVolume = 'access_volume';
    /** logs: a follow opened by the log explorer of `requested_by_id`. */
    case LogTail = 'log_tail';

    public static function forSession(InteractiveSessionKind $kind): self
    {
        return match ($kind) {
            InteractiveSessionKind::Console => self::ConsoleSession,
            InteractiveSessionKind::Data => self::DataSession,
            InteractiveSessionKind::Recovery => self::RecoverySession,
        };
    }

    public static function forAccess(RemoteAccessKind $kind): self
    {
        return match ($kind) {
            RemoteAccessKind::Ssh => self::AccessSsh,
            RemoteAccessKind::Database => self::AccessDatabase,
            RemoteAccessKind::Volume => self::AccessVolume,
        };
    }
}
