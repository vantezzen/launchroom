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
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Backs up whatever holds a service's data (docs/14 §1): a database dump or
 * one snapshot per backed-up volume, or says why it can't. Used by "Back up
 * now", "Back up everything now", the schedule tick and final backups
 * before deletion.
 *
 * Volumes start all or nothing: when one volume is refused, the runs of the
 * others are rolled back and the refusal is returned (no half-backed-up
 * service). Volumes whose backups are turned off are skipped; when every
 * volume is turned off there is nothing to do (an empty list, not a refusal).
 * $batch groups the runs of one "Back up everything now".
 */
final readonly class StartServiceBackup
{
    public function __construct(
        private StartBackupRun $runs,
    ) {}

    /**
     * @return list<BackupRun>|Refusal
     */
    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null, ?string $batch = null): array|Refusal
    {
        return match (BackupSubjects::for($instance)) {
            BackupSubject::Database => $this->database($instance, $reason, $user, $actor, $schedule, $batch),
            BackupSubject::Volume => $this->volumes($instance, $reason, $user, $actor, $schedule, $batch),
            default => Refusal::on('service', 'This service has no data to back up: only databases and services with volumes are backed up.'),
        };
    }

    /**
     * @return list<BackupRun>|Refusal
     */
    private function database(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule, ?string $batch): array|Refusal
    {
        $run = $this->runs->handle($instance, null, $reason, $user, $actor, $schedule, $batch);

        return $run instanceof Refusal ? $run : [$run];
    }

    /**
     * @return list<BackupRun>|Refusal
     */
    private function volumes(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule, ?string $batch): array|Refusal
    {
        if (BackupSubjects::placedVolumes($instance) === []) {
            return Refusal::on('service', 'This service’s volumes don’t exist on a server yet. Deploy it first.');
        }

        $volumes = BackupSubjects::backedUpVolumes($instance);
        $refusal = null;

        try {
            return DB::transaction(function () use ($instance, $volumes, $reason, $user, $actor, $schedule, $batch, &$refusal): array {
                $runs = [];

                foreach ($volumes as $volume) {
                    $run = $this->runs->handle($instance, $volume, $reason, $user, $actor, $schedule, $batch);

                    if ($run instanceof Refusal) {
                        $refusal = $run;

                        // Rolls back the runs (and their tasks) already created.
                        throw new RuntimeException('volume refused');
                    }

                    $runs[] = $run;
                }

                return $runs;
            });
        } catch (RuntimeException $e) {
            if ($refusal instanceof Refusal) {
                return $refusal;
            }

            throw $e;
        }
    }
}
