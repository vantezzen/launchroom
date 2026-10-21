<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Events\NodeStateInvalidated;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\RestoreHolds;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves restores forward (docs/14 §5.1), called by the scheduler tick:
 *
 * - waiting_for_safety_backup → restoring once the safety snapshot
 *   succeeded (failed: the restore fails and nothing was changed);
 * - waiting_for_target → restoring once the new service runs (fails after
 *   `restore_target_wait_minutes`);
 * - restoring → succeeded/failed comes from the task ({@see SyncBackupTasks}).
 *
 * The restore task is pinned to the server that runs the target database
 * and feeds `restic dump` into the engine's restore tool there. Volume
 * restores are pinned to the volume's server: once the target has been
 * deployed (its volume exists), the run turns `restoring`, which holds the
 * service (its workloads leave the NodeState, {@see RestoreHolds})
 * until the task finished.
 *
 * Restoring into a database holds its dependents (the services connected to
 * it in that environment) the same way: the run turns `restoring` without a
 * task, their servers re-render (launchroomd stops them), and the restore
 * task starts once none of their workloads is up any more, or after
 * `restore_hold_wait_seconds` (unreachable servers can't confirm; Postgres
 * restores are a single transaction anyway). The task's end releases them.
 */
final readonly class AdvanceRestores
{
    public function __construct(
        private BackupTasks $tasks,
        private PointInTimeRecovery $pointInTime,
    ) {}

    public function handle(): int
    {
        $advanced = 0;

        /** @var list<int> $ids */
        $ids = RestoreRun::query()
            ->where(static fn (Builder $query) => $query
                ->whereIn('status', [RestoreRunStatus::WaitingForSafetyBackup->value, RestoreRunStatus::WaitingForTarget->value])
                ->orWhere(static fn (Builder $holding) => $holding->where('status', RestoreRunStatus::Restoring->value)->whereNull('daemon_task_id')))
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            try {
                $advanced += $this->advance($id) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $advanced;
    }

    private function advance(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $run = RestoreRun::query()->whereKey($id)->with(['safetyBackup', 'destination'])->lockForUpdate()->first();

            if (! $run instanceof RestoreRun) {
                return false;
            }

            if ($run->status === RestoreRunStatus::WaitingForSafetyBackup) {
                $safety = $run->safetyBackup;

                if ($safety === null || $safety->status === BackupRunStatus::Failed) {
                    $run->transitionTo(RestoreRunStatus::Failed, 'The safety snapshot failed, so nothing was restored. The database is unchanged.');

                    return true;
                }

                if ($safety->status !== BackupRunStatus::Succeeded) {
                    return false;
                }
            }

            $target = $run->target_service_instance_id === null ? null
                : ServiceInstance::query()->whereKey($run->target_service_instance_id)->with('service')->first();

            if (! $target instanceof ServiceInstance) {
                $held = $run->status === RestoreRunStatus::Restoring ? RestoreHolds::heldBy($run) : [];
                $run->transitionTo(RestoreRunStatus::Failed, 'The target service no longer exists.');
                $this->rerender($held);

                return true;
            }

            if ($run->subject === BackupSubject::Volume) {
                return $this->volume($run, $target);
            }

            if ($run->subject === BackupSubject::BaseBackup) {
                return $this->replay($run, $target);
            }

            $placement = DatabaseWorkloads::servingPlacement($target);
            $source = DatabaseWorkloads::source($target);

            if (! $placement instanceof Placement || ! $source instanceof DatabaseSource) {
                if ($run->status === RestoreRunStatus::Restoring) {
                    // Holding its dependents, but the database itself stopped.
                    if ($run->started_at !== null && $run->started_at->lt(now()->subSeconds(config()->integer('launchroom.backups.restore_hold_wait_seconds')))) {
                        $held = RestoreHolds::heldBy($run);
                        $run->transitionTo(RestoreRunStatus::Failed, 'The database stopped before the restore could start. Nothing was restored.');
                        $this->rerender($held);

                        return true;
                    }

                    return false;
                }

                if ($run->created_at !== null && $run->created_at->lt(now()->subMinutes(config()->integer('launchroom.backups.restore_target_wait_minutes')))) {
                    $run->transitionTo(RestoreRunStatus::Failed, 'The database to restore into never started. Deploy it, then restore again.');

                    return true;
                }

                return false;
            }

            // Restoring into a database: its dependents stop first (docs/14 §5.1).
            $dependents = $run->mode === RestoreMode::InPlace ? RestoreHolds::dependentIds($target) : [];

            if ($dependents !== [] && $run->status !== RestoreRunStatus::Restoring) {
                $run->transitionTo(RestoreRunStatus::Restoring);
                $this->rerender($dependents);

                return true;
            }

            if ($dependents !== [] && ! RestoreHolds::released($dependents)
                && $run->started_at !== null && $run->started_at->gt(now()->subSeconds(config()->integer('launchroom.backups.restore_hold_wait_seconds')))) {
                return false;
            }

            $task = $this->tasks->restore(
                $run->destination,
                $placement->server,
                $run->team_id,
                $run->snapshot_id,
                ['engine' => DatabaseWorkloads::daemonEngine($source), 'workload' => $placement->workload_id],
                $run->mode->value,
            );

            $run->daemon_task_id = $task->id;

            if ($run->status === RestoreRunStatus::Restoring) {
                $run->save();
            } else {
                $run->transitionTo(RestoreRunStatus::Restoring);
            }

            return true;
        });
    }

    /**
     * Re-renders the servers of held (or released) instances: a hold that
     * starts stops their workloads, one that ends starts them again.
     *
     * @param  list<int>  $instanceIds
     */
    private function rerender(array $instanceIds): void
    {
        $servers = RestoreHolds::serverIds($instanceIds);

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }
    }

    /**
     * A volume restore starts once the target's volume exists on its server
     * (the target was deployed and its workload observed there).
     */
    private function volume(RestoreRun $run, ServiceInstance $target): bool
    {
        $volume = $run->volume_id === null ? null : Volume::query()->whereKey($run->volume_id)->where('service_instance_id', $target->id)->with('server')->first();

        if (! $volume instanceof Volume) {
            $run->transitionTo(RestoreRunStatus::Failed, 'The volume to restore into no longer exists.');

            return true;
        }

        $server = $volume->server;
        $observed = $server instanceof Server && $target->active_deployment_id !== null && Placement::query()
            ->where('service_instance_id', $target->id)
            ->where('server_id', $server->id)
            ->whereIn('phase', [WorkloadPhase::Running->value, WorkloadPhase::Healthy->value, WorkloadPhase::Unhealthy->value, WorkloadPhase::Exited->value])
            ->exists();

        if (! $observed || $server->status !== ServerStatus::Online) {
            if ($run->created_at !== null && $run->created_at->lt(now()->subMinutes(config()->integer('launchroom.backups.restore_target_wait_minutes')))) {
                $run->transitionTo(RestoreRunStatus::Failed, 'The service to restore into never started on its server. Deploy it, then restore again.');

                return true;
            }

            return false;
        }

        $task = $this->tasks->volumeRestore($run->destination, $server, $run->team_id, $run->snapshot_id, $volume->public_id, $run->mode->value);

        $run->daemon_task_id = $task->id;
        $run->transitionTo(RestoreRunStatus::Restoring);

        // Holds the service: its workloads leave the server's NodeState.
        event(new NodeStateInvalidated([$server->id]));

        return true;
    }

    /**
     * A point-in-time restore (docs/14 §10.3) starts once the target runs on
     * its server (a copy: deployed and observed): the task replaces its data
     * volume, so the database (and, in place, its dependents) is held until
     * the task finished. In place with point-in-time recovery on, the replay
     * keeps archiving into the volume (the new timeline's history is shipped
     * when the database runs again).
     */
    private function replay(RestoreRun $run, ServiceInstance $target): bool
    {
        $volume = $run->volume_id === null ? null : Volume::query()->whereKey($run->volume_id)->where('service_instance_id', $target->id)->with('server')->first();
        $source = DatabaseWorkloads::source($target);
        $placement = DatabaseWorkloads::servingPlacement($target);

        if (! $volume instanceof Volume || ! $source instanceof DatabaseSource) {
            $run->transitionTo(RestoreRunStatus::Failed, 'The database volume to restore into no longer exists.');

            return true;
        }

        $server = $volume->server;
        $deployment = $target->active_deployment_id === null ? null : Deployment::query()->with('revision')->find($target->active_deployment_id);
        $env = $this->pointInTime->postgresEnv($target);

        if (! $server instanceof Server || $server->status !== ServerStatus::Online || ! $placement instanceof Placement || ! $deployment instanceof Deployment || $deployment->image === null || $env === null) {
            if ($run->created_at !== null && $run->created_at->lt(now()->subMinutes(config()->integer('launchroom.backups.restore_target_wait_minutes')))) {
                $run->transitionTo(RestoreRunStatus::Failed, 'The database to restore into never started on its server. Deploy it, then restore again.');

                return true;
            }

            return false;
        }

        $scaling = $deployment->revision?->spec->service($target->service->public_id)?->scaling;
        $archive = $run->mode === RestoreMode::InPlace ? $this->pointInTime->enabledFor($target) : null;
        $task = $this->tasks->pointInTimeRestore(
            $run->destination,
            $server,
            $run->team_id,
            $run->snapshot_id,
            $volume->public_id,
            $run->mode->value,
            $run->point_in_time?->toImmutable()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            $this->pointInTime->walTags($run->source()->firstOrFail()),
            [
                'image' => $deployment->image,
                'mountPath' => $this->pointInTime->mountPath($source->version),
                'dataDirectory' => $this->pointInTime->dataDirectory($source->version),
                'archiveDirectory' => $archive instanceof WalArchive ? $this->pointInTime->archiveDirectory($source->version) : null,
                'cpus' => $scaling->cpus ?? 1.0,
                'memoryMb' => max($scaling->memoryMb ?? 1024, 256),
                'pids' => $scaling?->pids() ?? 512,
            ],
            $env,
        );

        $run->daemon_task_id = $task->id;
        $run->transitionTo(RestoreRunStatus::Restoring);
        $this->rerender(RestoreHolds::heldBy($run));

        return true;
    }
}
