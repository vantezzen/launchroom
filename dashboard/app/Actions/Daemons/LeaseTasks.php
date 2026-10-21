<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Exceptions\TaskSealingFailed;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Tasks\TaskSecrets;
use App\Support\ServerPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leases up to `max` pending tasks to a server (docs/03 §7, docs/07).
 *
 * `SELECT … FOR UPDATE SKIP LOCKED` lets daemons lease concurrently without
 * blocking or double-leasing. A server only gets tasks pinned to it, or
 * unpinned tasks of its own team that its roles can run (builds and plans
 * only when the server's platform matches). Secrets are sealed to the
 * leasing server here (docs/03 §7, App\Services\Tasks\TaskSecrets); a
 * task whose secrets can't be sealed fails instead of being handed out.
 */
final readonly class LeaseTasks
{
    public function __construct(
        private RequeueExpiredLeases $requeueExpiredLeases,
        private TaskSecrets $secrets,
    ) {}

    /**
     * @return list<Task> the leased tasks with their specs materialized for $server
     */
    public function handle(Server $server, LeaseRequest $request): array
    {
        $this->requeueExpiredLeases->handle();

        return DB::transaction(function () use ($server, $request): array {
            $roles = $server->roles->map(static fn (ServerRole $role): string => $role->value)->all();
            $platform = ServerPlatform::of($server);
            // Its own platform and the ones its engine emulates (D53).
            $platforms = ServerPlatform::buildable($server);

            $tasks = DaemonTask::query()
                ->where('status', DaemonTaskStatus::Pending->value)
                ->whereIn('type', array_map(static fn (DaemonTaskType $type): string => $type->value, $request->taskTypes()))
                ->where(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($server, $roles): void {
                    $query
                        ->where(fn (\Illuminate\Contracts\Database\Query\Builder $pinned) => $pinned->where('pinned_to_server', true)->where('server_id', $server->id))
                        ->orWhere(fn (\Illuminate\Contracts\Database\Query\Builder $pool) => $pool
                            ->where('pinned_to_server', false)
                            ->where('team_id', $server->team_id)
                            ->where(static function (\Illuminate\Contracts\Database\Query\Builder $role) use ($roles): void {
                                /** @var Builder<DaemonTask> $role */
                                $role->whereNull('required_role')->orWhereIn('required_role', $roles);
                            }));
                })
                // Builds and plans only go to servers that build the target platform.
                ->when($platform !== null, static fn (Builder $query) => $query->where(static function (\Illuminate\Contracts\Database\Query\Builder $arch) use ($platforms): void {
                    $arch->whereNotIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Plan->value])
                        ->orWhereRaw("spec->>'platform' IN (".implode(', ', array_fill(0, count($platforms), '?')).')', $platforms);
                }))
                // Builds from GitLab/Gitea, over SSH or with a fallback ref need
                // a launchroomd that understands those fields (docs/19 §6).
                ->when(! $server->supportsDaemonFeature(DaemonFeature::GitProviders), static fn (Builder $query) => $query->where(static function (\Illuminate\Contracts\Database\Query\Builder $git): void {
                    $git->whereNotIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Plan->value])
                        ->orWhereRaw("NOT ((spec->'repo'->>'provider') IN ('gitlab', 'gitea') OR (spec->'repo'->>'cloneUrl') LIKE 'ssh://%' OR (spec->'repo'->>'ref') IS NOT NULL)");
                }))
                // Generated Dockerfiles: inline Dockerfile builds, inspecting
                // plans and probing one-off runs (docs/20 §6).
                ->when(! $server->supportsDaemonFeature(DaemonFeature::GeneratedDockerfile), static fn (Builder $query) => $query->where(static function (\Illuminate\Contracts\Database\Query\Builder $generated): void {
                    // COALESCE: a missing `inspect` is NULL, and NOT (… OR NULL) would drop every other task.
                    $generated->whereRaw("NOT ((spec->'builder'->>'dockerfile') IS NOT NULL OR COALESCE(spec->>'inspect', '') = 'true' OR (spec->'probe') IS NOT NULL)");
                }))
                ->where(fn (\Illuminate\Contracts\Database\Query\Builder $query) => $query->whereNull('deadline_at')->orWhere('deadline_at', '>', now()))
                ->orderBy('id')
                ->limit($request->max)
                ->lock('for update skip locked')
                ->get();

            $leaseSeconds = config()->integer('launchroom.daemon.lease_seconds');
            $leased = [];

            foreach ($tasks as $task) {
                $task->forceFill([
                    'server_id' => $server->id,
                    'attempt' => $task->attempt + 1,
                    'leased_at' => now(),
                    'lease_expires_at' => now()->addSeconds($leaseSeconds),
                    'deadline_at' => $task->deadline_at ?? now()->addSeconds($task->timeout_seconds),
                ]);
                $task->transitionTo(DaemonTaskStatus::Leased);

                try {
                    $leased[] = Task::fromTask($task, $this->secrets->materialize($task, $server));
                } catch (TaskSealingFailed $e) {
                    // Never hand out a task whose secrets can't be delivered.
                    $task->forceFill([
                        'completed_attempt' => $task->attempt,
                        'lease_expires_at' => null,
                        'error' => mb_substr($e->getMessage(), 0, 4000),
                        'result' => ['failure' => ['class' => $e->failureClass, 'retryable' => $e->failureClass === 'transient', 'step' => 'setup']],
                    ]);
                    $task->transitionTo(DaemonTaskStatus::Failed);
                }
            }

            return $leased;
        });
    }
}
