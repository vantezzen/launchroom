<?php

declare(strict_types=1);

namespace App\Events\Backups;

use App\Enums\Backups\BackupAlertKind;
use App\Listeners\Backups\ForwardBackupAlerts;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup problem started (or is still there; raised again at most every
 * 30 minutes): failed backup, failed verification, overdue backup, WAL
 * archiving lag (docs/14 §10.6). {@see ForwardBackupAlerts}
 * hands it to the alerts API (docs/15 §3.2). Text is secret-free and holds
 * no log excerpts: it goes to chat services.
 */
final readonly class BackupAlertRaised
{
    use Dispatchable;

    public function __construct(
        public int $teamId,
        public BackupAlertKind $kind,
        public int $serviceInstanceId,
        /** `<kind>:<instance public id>`. */
        public string $subject,
        public string $title,
        public string $summary,
        /** A new occurrence (the failed run/verification), for repeat notifications. */
        public ?string $occurrence = null,
    ) {}
}
