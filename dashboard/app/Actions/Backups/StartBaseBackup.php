<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\WalArchive;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\SnapshotTags;
use Illuminate\Support\Facades\DB;

/**
 * Takes a Postgres base backup now (docs/14 §10.1): a `backup` task of kind
 * base_backup on the database's server (pg_basebackup into restic). The run
 * (subject base_backup) follows the task like a dump's. At most one base
 * backup per database is in flight; asking again returns it.
 */
final readonly class StartBaseBackup
{
    public function __construct(
        private BackupTasks $tasks,
        private PointInTimeRecovery $pointInTime,
    ) {}

    public function handle(WalArchive $archive, BackupReason $reason, ?User $user = null): BackupRun
    {
        return DB::transaction(function () use ($archive, $reason, $user): BackupRun {
            $instance = ServiceInstance::query()->whereKey($archive->service_instance_id)->with(['service.project.team', 'environment.project'])->lockForUpdate()->firstOrFail();

            $inFlight = BackupRun::query()
                ->where('service_instance_id', $instance->id)
                ->where('subject', BackupSubject::BaseBackup->value)
                ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
                ->first();

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            if (! $this->pointInTime->supports($instance)) {
                throw DomainRuleViolation::on('service', 'Point-in-time recovery needs Postgres 15 or newer.');
            }

            $placement = DatabaseWorkloads::servingPlacement($instance);

            if (! $placement instanceof Placement || $placement->server->status === ServerStatus::Revoked) {
                throw DomainRuleViolation::on('service', 'This database isn’t running, so there is nothing to back up. Deploy it first.');
            }

            $tags = SnapshotTags::forDatabase($instance, $placement->server, 'postgres', $reason);
            $tags[array_search('kind:database', $tags, true)] = 'kind:base_backup';

            $task = $this->tasks->baseBackup($archive->destination()->firstOrFail(), $placement->server, $instance->team_id, $placement->workload_id, $tags);

            return BackupRun::query()->create([
                'team_id' => $instance->team_id,
                'destination_id' => $archive->destination_id,
                'subject' => BackupSubject::BaseBackup,
                'service_instance_id' => $instance->id,
                'reason' => $reason,
                'daemon_task_id' => $task->id,
                'server_id' => $placement->server_id,
                'actor_id' => $user?->id,
            ]);
        });
    }
}
