<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\SnapshotTags;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * Starts one backup run of a service (docs/14 §2), or says why it can't.
 * At most one run per database or volume is in flight (a partial unique
 * index); asking again while one runs returns that run. The destination is
 * the schedule's, else the team's default. Runs are audited unless the
 * schedule started them.
 *
 * - database: a `backup` task pinned to the server that runs the database.
 *   The daemon streams the engine's dump into `restic backup --stdin`.
 * - volume (a service that is not a database): a `backup` task of kind
 *   volume pinned to the volume's server. launchroomd reads the volume
 *   through a helper container (read-only mount) as a tar stream; with
 *   consistency `pause` the service's containers are frozen while it is
 *   read. The service need not run (a final backup before deletion works on
 *   a stopped one too).
 *
 * With a volume it backs up that volume, otherwise the database. The run
 * follows its task (SyncBackupTasks). StartDatabaseBackup and
 * StartVolumeBackup are the throwing entry points for user-facing starts.
 */
final readonly class StartBackupRun
{
    public function __construct(
        private BackupTasks $tasks,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, ?Volume $volume, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null, ?string $batch = null): BackupRun|Refusal
    {
        return $volume instanceof Volume
            ? $this->volume($instance, $volume, $reason, $user, $actor, $schedule, $batch)
            : $this->database($instance, $reason, $user, $actor, $schedule, $batch);
    }

    private function database(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule, ?string $batch): BackupRun|Refusal
    {
        return DB::transaction(function () use ($instance, $reason, $user, $actor, $schedule, $batch): BackupRun|Refusal {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service.project.team', 'environment.project'])->lockForUpdate()->firstOrFail();
            $inFlight = $this->inFlight($instance, null);

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            $source = DatabaseWorkloads::source($instance);

            if ($instance->service->kind !== ServiceKind::Database || ! $source instanceof DatabaseSource) {
                return Refusal::on('service', 'Only database services are backed up in this version.');
            }

            $schedule ??= BackupSchedule::ofInstance($instance);
            $destination = BackupDestination::forService($instance, $schedule);

            if (! $destination instanceof BackupDestination) {
                return $this->noDestination();
            }

            $placement = DatabaseWorkloads::servingPlacement($instance);

            if (! $placement instanceof Placement || $placement->server->status === ServerStatus::Revoked) {
                return Refusal::on('service', 'This database isn’t running, so there is nothing to back up. Deploy it first.');
            }

            $unsupported = DatabaseWorkloads::unsupportedBy($source, $placement->server);

            if ($unsupported !== null) {
                return Refusal::on('daemon', $unsupported);
            }

            $engine = $source->engine->value;
            $task = $this->tasks->backup(
                $destination,
                $placement->server,
                $instance->team_id,
                ['engine' => $engine, 'workload' => $placement->workload_id],
                SnapshotTags::forDatabase($instance, $placement->server, $engine, $reason),
            );

            return $this->record($instance, BackupSubject::Database, null, $reason, $destination, $schedule, $task, $placement->server_id, $user, $actor, $batch);
        });
    }

    private function volume(ServiceInstance $instance, Volume $volume, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule, ?string $batch): BackupRun|Refusal
    {
        return DB::transaction(function () use ($instance, $volume, $reason, $user, $actor, $schedule, $batch): BackupRun|Refusal {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service.project.team', 'environment.project', 'volumes'])->lockForUpdate()->firstOrFail();
            $volume = Volume::query()->whereKey($volume->id)->where('service_instance_id', $instance->id)->with('server')->firstOrFail();
            $inFlight = $this->inFlight($instance, $volume);

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            if (BackupSubjects::for($instance) !== BackupSubject::Volume) {
                return Refusal::on('service', 'Databases are backed up as dumps, not volume snapshots.');
            }

            // A restore into it still takes its safety copy: that is the user's undo.
            if (BackupSubjects::excluded($volume) && $reason !== BackupReason::PreRestore) {
                return Refusal::on('volume', "Backups of {$volume->mount_path} are turned off. Turn them on to back it up.");
            }

            $schedule ??= BackupSchedule::ofInstance($instance);
            $destination = BackupDestination::forService($instance, $schedule);

            if (! $destination instanceof BackupDestination) {
                return $this->noDestination();
            }

            $server = $volume->server;

            if (! $server instanceof Server || ! $server->status->isApproved()) {
                return Refusal::on('service', "The volume {$volume->mount_path} hasn’t been created on a server yet (deploy the service first), or its server was removed.");
            }

            if ($server->status !== ServerStatus::Online) {
                return Refusal::on('service', "The server {$server->name} that holds {$volume->mount_path} is {$server->status->label()}, so the volume can’t be read right now.");
            }

            $task = $this->tasks->volumeBackup(
                $destination,
                $server,
                $instance->team_id,
                $volume->public_id,
                $volume->backup_consistency->value,
                SnapshotTags::forVolume($instance, $volume, $server, $reason),
            );

            return $this->record($instance, BackupSubject::Volume, $volume, $reason, $destination, $schedule, $task, $server->id, $user, $actor, $batch);
        });
    }

    private function noDestination(): Refusal
    {
        return Refusal::on('destination', 'Connect backup storage in Settings → Backups first.');
    }

    private function inFlight(ServiceInstance $instance, ?Volume $volume): ?BackupRun
    {
        $query = BackupRun::query()->where('service_instance_id', $instance->id)->whereIn('status', BackupRunStatus::nonTerminal());

        return ($volume instanceof Volume ? $query->where('volume_id', $volume->id) : $query->where('subject', BackupSubject::Database->value))->first();
    }

    private function record(ServiceInstance $instance, BackupSubject $subject, ?Volume $volume, BackupReason $reason, BackupDestination $destination, ?BackupSchedule $schedule, DaemonTask $task, int $serverId, ?User $user, AuditActor $actor, ?string $batch): BackupRun
    {
        $run = BackupRun::query()->create([
            'team_id' => $instance->team_id,
            'schedule_id' => $schedule?->id,
            'destination_id' => $destination->id,
            'subject' => $subject,
            'service_instance_id' => $instance->id,
            'volume_id' => $volume?->id,
            'reason' => $reason,
            'daemon_task_id' => $task->id,
            'server_id' => $serverId,
            'actor_id' => $user?->id,
            'batch_id' => $batch,
        ]);

        if ($reason !== BackupReason::Scheduled) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter(['run' => $run->public_id, 'reason' => $reason->value, 'environment' => $instance->environment->public_id, 'volume' => $volume?->public_id], static fn (mixed $value): bool => $value !== null),
            ));
        }

        return $run;
    }
}
