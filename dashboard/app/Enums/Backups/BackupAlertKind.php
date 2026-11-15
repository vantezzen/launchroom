<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Backup problems that alert (docs/14 §10.6). The value is the prefix of the
 * alert subject key (`backup:<instance public id>`, …).
 */
enum BackupAlertKind: string
{
    /** The newest backup run (dump, volume snapshot or base backup) failed. */
    case Failed = 'backup';
    /** The newest restore verification failed. */
    case VerificationFailed = 'backup_verify';
    /** Scheduled, but no successful backup for longer than `stale_after_hours`. */
    case Overdue = 'backup_overdue';
    /** Point-in-time recovery is on and WAL is not reaching the destination. */
    case WalLagging = 'wal_archive';

    public function label(): string
    {
        return match ($this) {
            self::Failed => 'Backup failed',
            self::VerificationFailed => 'Backup check failed',
            self::Overdue => 'Backup overdue',
            self::WalLagging => 'Archiving behind',
        };
    }

    public static function ofSubject(string $subjectKey): ?self
    {
        return self::tryFrom(explode(':', $subjectKey, 2)[0]);
    }

    public function critical(): bool
    {
        return $this === self::Failed || $this === self::VerificationFailed;
    }
}
