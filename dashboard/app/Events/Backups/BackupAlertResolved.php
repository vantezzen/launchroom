<?php

declare(strict_types=1);

namespace App\Events\Backups;

use App\Enums\Backups\BackupAlertKind;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup problem is gone (the next backup or verification succeeded, WAL
 * reaches the destination again; docs/14 §10.6).
 */
final readonly class BackupAlertResolved
{
    use Dispatchable;

    public function __construct(
        public int $teamId,
        public BackupAlertKind $kind,
        public int $serviceInstanceId,
        public string $subject,
        public string $reason,
    ) {}
}
