<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Support\Refusal;

/**
 * Backs up one volume of a service that is not a database now (docs/14 §2),
 * or throws why it can't.
 */
final readonly class StartVolumeBackup
{
    public function __construct(
        private StartBackupRun $runs,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(ServiceInstance $instance, Volume $volume, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null): BackupRun
    {
        return Refusal::orThrow($this->runs->handle($instance, $volume, $reason, $user, $actor, $schedule));
    }
}
