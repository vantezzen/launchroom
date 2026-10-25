<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\VerificationReason;
use App\Enums\Backups\VerificationStatus;
use App\Enums\DaemonFeature;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\WalArchive;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the newest backup of a service by restoring it (docs/14 §10.4):
 * a `verify_restore` task on the server the backup was taken on (it has the
 * engine image), into a throwaway container with no network. With
 * point-in-time recovery on, the newest base backup is verified together
 * with the archived WAL (it is what a restore would use); otherwise the
 * newest dump or volume snapshot. One verification per service at a time;
 * asking again returns it.
 */
final readonly class StartVerification
{
    public function __construct(
        private BackupTasks $tasks,
        private PointInTimeRecovery $pointInTime,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, VerificationReason $reason, ?User $user, AuditActor $actor): BackupVerification
    {
        return DB::transaction(function () use ($instance, $reason, $user, $actor): BackupVerification {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment.project', 'volumes'])->lockForUpdate()->firstOrFail();

            $inFlight = BackupVerification::query()->where('service_instance_id', $instance->id)
                ->whereIn('status', [VerificationStatus::Pending->value, VerificationStatus::Running->value])->first();

            if ($inFlight instanceof BackupVerification) {
                return $inFlight;
            }

            $run = $this->runToVerify($instance);

            if (! $run instanceof BackupRun || $run->snapshot_id === null) {
                throw DomainRuleViolation::on('service', 'There is no successful backup to verify yet.');
            }

            $server = $run->subject === BackupSubject::Volume ? $run->volume?->server : $run->server;

            if (! $server instanceof Server || $server->status !== ServerStatus::Online) {
                throw DomainRuleViolation::on('service', 'The server this backup was taken on is offline, so it can’t be verified right now.');
            }

            if (! $server->supportsDaemonFeature(DaemonFeature::VerifyRestore)) {
                throw DomainRuleViolation::on('daemon', "Update {$server->name} in Settings → Updates to verify backups.");
            }

            $source = DatabaseWorkloads::source($instance);
            $unsupported = $source instanceof DatabaseSource && $run->subject !== BackupSubject::Volume ? DatabaseWorkloads::unsupportedBy($source, $server, 'verify backups of') : null;

            if ($unsupported !== null) {
                throw DomainRuleViolation::on('daemon', $unsupported);
            }

            $spec = $this->spec($instance, $run);
            $task = $this->tasks->verify($run->destination()->firstOrFail(), $server, $instance->team_id, $spec);

            $verification = BackupVerification::query()->create([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'backup_run_id' => $run->id,
                'destination_id' => $run->destination_id,
                'subject' => $run->subject,
                'volume_id' => $run->volume_id,
                'snapshot_id' => $run->snapshot_id,
                'snapshot_taken_at' => $run->finished_at,
                'reason' => $reason,
                'daemon_task_id' => $task->id,
                'server_id' => $server->id,
                'actor_id' => $user?->id,
            ]);

            if ($reason === VerificationReason::Manual) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupVerificationStarted,
                    actor: $actor,
                    teamId: $instance->team_id,
                    subjectType: 'service',
                    subjectId: $instance->service->public_id,
                    meta: ['verification' => $verification->public_id, 'snapshot' => substr($run->snapshot_id, 0, 16), 'environment' => $instance->environment->public_id],
                ));
            }

            return $verification;
        });
    }

    private function runToVerify(ServiceInstance $instance): ?BackupRun
    {
        $runs = BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->with(['server', 'volume.server'])
            ->orderByDesc('finished_at')
            ->orderByDesc('id');

        if ($this->pointInTime->enabledFor($instance) instanceof WalArchive) {
            $base = (clone $runs)->where('subject', BackupSubject::BaseBackup->value)->first();

            if ($base instanceof BackupRun) {
                return $base;
            }
        }

        return $runs->whereIn('subject', [BackupSubject::Database->value, BackupSubject::Volume->value])->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(ServiceInstance $instance, BackupRun $run): array
    {
        $resources = [
            'cpus' => config()->float('launchroom.backups.verification.cpus'),
            'memoryMb' => config()->integer('launchroom.backups.verification.memory_mb'),
            'pids' => config()->integer('launchroom.backups.verification.pids'),
        ];

        if ($run->subject === BackupSubject::Volume) {
            return ['kind' => 'volume', 'snapshot' => $run->snapshot_id, 'resources' => $resources];
        }

        $source = DatabaseWorkloads::source($instance);
        $deployment = $instance->active_deployment_id === null ? null : Deployment::query()->find($instance->active_deployment_id);
        $volume = $instance->volumes->sortBy('id')->first();

        if (! $source instanceof DatabaseSource || ! $deployment instanceof Deployment || $deployment->image === null || $volume === null) {
            throw DomainRuleViolation::on('service', 'Deploy this database first: its image is used to verify the backup.');
        }

        $spec = [
            'kind' => $run->subject === BackupSubject::BaseBackup ? 'base_backup' : 'database',
            'snapshot' => $run->snapshot_id,
            'engine' => DatabaseWorkloads::daemonEngine($source),
            'image' => $deployment->image,
            'dataPath' => $volume->mount_path,
            'resources' => $resources,
        ];

        if ($run->subject === BackupSubject::BaseBackup) {
            $env = $this->pointInTime->postgresEnv($instance);

            if ($env === null) {
                throw DomainRuleViolation::on('service', 'This database’s POSTGRES_USER or POSTGRES_DB can’t be used to verify it.');
            }

            $spec += [
                'dataDirectory' => $this->pointInTime->dataDirectory($source->version),
                'walTags' => $this->pointInTime->walTags($instance),
                'databaseUser' => $env['POSTGRES_USER'],
                'databaseName' => $env['POSTGRES_DB'],
            ];
        }

        return $spec;
    }
}
