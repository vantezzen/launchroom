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
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\SnapshotTags;
use Illuminate\Support\Facades\DB;

/**
 * Backs up one database now (docs/14 §2): a `backup` task pinned to the
 * server that runs the database. The daemon streams the engine's dump into
 * `restic backup --stdin`; the run follows the task ({@see SyncBackupRuns}).
 *
 * At most one backup per database is in flight (a partial unique index);
 * asking again while one runs returns that run.
 */
final readonly class StartDatabaseBackup
{
    public function __construct(
        private BackupTasks $tasks,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor, ?BackupSchedule $schedule = null): BackupRun
    {
        return DB::transaction(function () use ($instance, $reason, $user, $actor, $schedule): BackupRun {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service.project.team', 'environment.project'])->lockForUpdate()->firstOrFail();

            $inFlight = BackupRun::query()
                ->where('service_instance_id', $instance->id)
                ->where('subject', BackupSubject::Database->value)
                ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
                ->first();

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            $source = DatabaseWorkloads::source($instance);

            if ($instance->service->kind !== ServiceKind::Database || ! $source instanceof DatabaseSource) {
                throw DomainRuleViolation::on('service', 'Only database services are backed up in this version.');
            }

            $schedule ??= BackupSchedule::query()->where('service_instance_id', $instance->id)->first();
            $destination = $schedule?->destination()->first() ?? BackupDestination::defaultFor($instance->team_id);

            if (! $destination instanceof BackupDestination) {
                throw DomainRuleViolation::on('destination', 'Add a backup destination in Settings → Backups first.');
            }

            $placement = DatabaseWorkloads::servingPlacement($instance);

            if (! $placement instanceof Placement || $placement->server->status === ServerStatus::Revoked) {
                throw DomainRuleViolation::on('service', 'This database isn’t running, so there is nothing to back up. Deploy it first.');
            }

            $unsupported = DatabaseWorkloads::unsupportedBy($source, $placement->server);

            if ($unsupported !== null) {
                throw DomainRuleViolation::on('daemon', $unsupported);
            }

            $engine = DatabaseWorkloads::daemonEngine($source);
            $task = $this->tasks->backup(
                $destination,
                $placement->server,
                $instance->team_id,
                ['engine' => $engine, 'workload' => $placement->workload_id],
                SnapshotTags::forDatabase($instance, $placement->server, $engine, $reason),
            );

            $run = BackupRun::query()->create([
                'team_id' => $instance->team_id,
                'schedule_id' => $schedule?->id,
                'destination_id' => $destination->id,
                'subject' => BackupSubject::Database,
                'service_instance_id' => $instance->id,
                'reason' => $reason,
                'daemon_task_id' => $task->id,
                'server_id' => $placement->server_id,
                'actor_id' => $user?->id,
            ]);

            if ($reason !== BackupReason::Scheduled) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupStarted,
                    actor: $actor,
                    teamId: $instance->team_id,
                    subjectType: 'service',
                    subjectId: $instance->service->public_id,
                    meta: ['run' => $run->public_id, 'reason' => $reason->value, 'environment' => $instance->environment->public_id],
                ));
            }

            return $run;
        });
    }
}
