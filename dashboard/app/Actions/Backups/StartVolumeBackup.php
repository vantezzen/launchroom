<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\SnapshotTags;
use Illuminate\Support\Facades\DB;

/**
 * Backs up one volume of a service that is not a database (docs/14 §2): a
 * `backup` task of kind volume pinned to the volume's server. launchroomd
 * reads the volume through a helper container (read-only mount) as a tar
 * stream into `restic backup --stdin`; with consistency `pause` the
 * service's containers are frozen while it is read. The service need not run
 * (a final backup before deletion works on a stopped one too).
 *
 * At most one backup per volume is in flight; asking again returns it.
 */
final readonly class StartVolumeBackup
{
    public function __construct(
        private BackupTasks $tasks,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, Volume $volume, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null): BackupRun
    {
        return DB::transaction(function () use ($instance, $volume, $reason, $user, $actor, $schedule): BackupRun {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service.project.team', 'environment.project', 'volumes'])->lockForUpdate()->firstOrFail();
            $volume = Volume::query()->whereKey($volume->id)->where('service_instance_id', $instance->id)->with('server')->firstOrFail();

            $inFlight = BackupRun::query()
                ->where('service_instance_id', $instance->id)
                ->where('volume_id', $volume->id)
                ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
                ->first();

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            if (BackupSubjects::for($instance) !== BackupSubject::Volume) {
                throw DomainRuleViolation::on('service', 'Databases are backed up as dumps, not volume snapshots.');
            }

            $schedule ??= BackupSchedule::query()->where('service_instance_id', $instance->id)->first();
            $destination = $schedule?->destination()->first() ?? BackupDestination::defaultFor($instance->team_id);

            if (! $destination instanceof BackupDestination) {
                throw DomainRuleViolation::on('destination', 'Add a backup destination in Settings → Backups first.');
            }

            $server = $volume->server;

            if (! $server instanceof Server || ! $server->status->isApproved()) {
                throw DomainRuleViolation::on('service', "The volume {$volume->mount_path} hasn’t been created on a server yet (deploy the service first), or its server was removed.");
            }

            if ($server->status !== ServerStatus::Online) {
                throw DomainRuleViolation::on('service', "The server {$server->name} that holds {$volume->mount_path} is {$server->status->label()}, so the volume can’t be read right now.");
            }

            $task = $this->tasks->volumeBackup(
                $destination,
                $server,
                $instance->team_id,
                $volume->public_id,
                $volume->backup_consistency->value,
                SnapshotTags::forVolume($instance, $volume, $server, $reason),
            );

            $run = BackupRun::query()->create([
                'team_id' => $instance->team_id,
                'schedule_id' => $schedule?->id,
                'destination_id' => $destination->id,
                'subject' => BackupSubject::Volume,
                'service_instance_id' => $instance->id,
                'volume_id' => $volume->id,
                'reason' => $reason,
                'daemon_task_id' => $task->id,
                'server_id' => $server->id,
                'actor_id' => $user?->id,
            ]);

            if ($reason !== BackupReason::Scheduled) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupStarted,
                    actor: $actor,
                    teamId: $instance->team_id,
                    subjectType: 'service',
                    subjectId: $instance->service->public_id,
                    meta: ['run' => $run->public_id, 'reason' => $reason->value, 'environment' => $instance->environment->public_id, 'volume' => $volume->public_id],
                ));
            }

            return $run;
        });
    }
}
