<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Data\Backups\SafetySnapshot;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use App\Support\Refusal;

/**
 * Automatic safety snapshot before a risky operation on a service's data
 * (docs/14 §3): an import, a server move, deleting or restoring into it. It
 * never blocks the operation it protects: whatever can't be backed up (no
 * data, no destination, not running) is skipped, and the outcome says why.
 * Databases get a dump, other services one snapshot per backed-up volume on
 * a server (or only $volume); volumes whose backups are turned off are
 * skipped. The destination is the one its backups use: the
 * schedule's, else the team's default.
 *
 * Callers that must wait for the snapshot watch the runs' status. A restore
 * in place takes its own, required snapshot (StartRestore). The
 * control-plane equivalent is {@see StartControlPlaneBackup}.
 *
 * There is no local fallback destination: without one the snapshot is
 * skipped (the UI already nags until one exists).
 */
final readonly class TakeSafetySnapshot
{
    public function __construct(
        private StartBackupRun $runs,
    ) {}

    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?Volume $volume = null): SafetySnapshot
    {
        $subject = BackupSubjects::for($instance);

        if (! $subject instanceof BackupSubject) {
            return new SafetySnapshot(skipped: [Refusal::on('service', 'This service has no data to back up.')]);
        }

        if (! BackupDestination::forService($instance) instanceof BackupDestination) {
            return new SafetySnapshot(skipped: [Refusal::on('destination', 'There is no backup destination.')]);
        }

        if ($volume instanceof Volume && BackupSubjects::excluded($volume)) {
            return new SafetySnapshot(skipped: [Refusal::on('volume', "Backups of {$volume->mount_path} are turned off.")]);
        }

        $results = $subject === BackupSubject::Database
            ? [$this->runs->handle($instance, null, $reason, $user, $actor)]
            : array_map(fn (Volume $each): BackupRun|Refusal => $this->runs->handle($instance, $each, $reason, $user, $actor), $volume instanceof Volume ? [$volume] : BackupSubjects::backedUpVolumes($instance));

        return new SafetySnapshot(
            array_values(array_filter($results, static fn (mixed $result): bool => ! $result instanceof Refusal)),
            array_values(array_filter($results, static fn (mixed $result): bool => $result instanceof Refusal)),
        );
    }
}
