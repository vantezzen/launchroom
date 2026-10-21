<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupSubject;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\BackupSubjects;

/**
 * Backs up whatever holds a service's data (docs/14 §1): a database dump
 * ({@see StartDatabaseBackup}) or one snapshot per volume
 * ({@see StartVolumeBackup}). Used by "Back up now", the schedule tick and
 * final backups before deletion.
 */
final readonly class StartServiceBackup
{
    public function __construct(
        private StartDatabaseBackup $database,
        private StartVolumeBackup $volume,
    ) {}

    /**
     * @return list<BackupRun>
     *
     * @throws DomainRuleViolation when nothing could be started
     */
    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null): array
    {
        return match (BackupSubjects::for($instance)) {
            BackupSubject::Database => [$this->database->handle($instance, $reason, $user, $actor, $schedule)],
            BackupSubject::Volume => $this->volumes($instance, $reason, $user, $actor, $schedule),
            default => throw DomainRuleViolation::on('service', 'This service has no data to back up: only databases and services with volumes are backed up.'),
        };
    }

    /**
     * @return list<BackupRun>
     */
    private function volumes(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule): array
    {
        $volumes = BackupSubjects::placedVolumes($instance);

        if ($volumes === []) {
            throw DomainRuleViolation::on('service', 'This service’s volumes don’t exist on a server yet. Deploy it first.');
        }

        $runs = [];

        foreach ($volumes as $volume) {
            $runs[] = $this->volume->handle($instance, $volume, $reason, $user, $actor, $schedule);
        }

        return $runs;
    }
}
