<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * What asking to delete an environment or project did (docs/14 §9.13).
 */
enum DeletionOutcome: string
{
    /** Deleted (teardown started). */
    case Deleted = 'deleted';

    /** Final backups are running; the deletion continues by itself when they succeeded. */
    case WaitingForFinalBackup = 'waiting_for_final_backup';

    /** A final backup failed or could not start; nothing was deleted. */
    case Blocked = 'blocked';

    public static function forStatus(DeletionRequestStatus $status): self
    {
        return $status === DeletionRequestStatus::Blocked ? self::Blocked : self::WaitingForFinalBackup;
    }
}
