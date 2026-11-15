<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\BackupSubjects;
use App\Support\Refusal;

/**
 * Backs up whatever holds a service's data (docs/14 §1): a database dump or
 * one snapshot per volume, or says why it can't. Used by "Back up now", the
 * schedule tick and final backups before deletion.
 */
final readonly class StartServiceBackup
{
    public function __construct(
        private StartBackupRun $runs,
    ) {}

    /**
     * @return list<BackupRun>|Refusal
     */
    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null): array|Refusal
    {
        return match (BackupSubjects::for($instance)) {
            BackupSubject::Database => $this->database($instance, $reason, $user, $actor, $schedule),
            BackupSubject::Volume => $this->volumes($instance, $reason, $user, $actor, $schedule),
            default => Refusal::on('service', 'This service has no data to back up: only databases and services with volumes are backed up.'),
        };
    }

    /**
     * @return list<BackupRun>|Refusal
     */
    private function database(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule): array|Refusal
    {
        $run = $this->runs->handle($instance, null, $reason, $user, $actor, $schedule);

        return $run instanceof Refusal ? $run : [$run];
    }

    /**
     * @return list<BackupRun>|Refusal
     */
    private function volumes(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule): array|Refusal
    {
        $volumes = BackupSubjects::placedVolumes($instance);

        if ($volumes === []) {
            return Refusal::on('service', 'This service’s volumes don’t exist on a server yet. Deploy it first.');
        }

        $runs = [];

        foreach ($volumes as $volume) {
            $run = $this->runs->handle($instance, $volume, $reason, $user, $actor, $schedule);

            if ($run instanceof Refusal) {
                return $run;
            }

            $runs[] = $run;
        }

        return $runs;
    }
}
