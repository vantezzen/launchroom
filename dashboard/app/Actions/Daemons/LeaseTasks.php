<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskFailure;
use App\Data\Protocol\V1\TaskResult;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\DaemonTaskLeased;
use App\Exceptions\TaskSealingFailed;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Tasks\TaskEligibility;
use App\Services\Tasks\TaskSecrets;
use Illuminate\Support\Facades\DB;

/**
 * Leases up to `max` pending tasks to a server (docs/03 §7, docs/07), the
 * ones TaskEligibility allows it. `SELECT … FOR UPDATE SKIP LOCKED` lets
 * daemons lease concurrently without blocking or double-leasing. Secrets
 * are sealed to the leasing server here (App\Services\Tasks\TaskSecrets);
 * a task whose secrets can't be sealed fails instead of being handed out.
 * Owners that change state when their task is leased (a session opens)
 * listen to DaemonTaskLeased, inside the lease transaction.
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
            $tasks = TaskEligibility::leasableBy(DaemonTask::query(), $server)
                ->where('status', DaemonTaskStatus::Pending->value)
                ->whereIn('type', array_map(static fn (DaemonTaskType $type): string => $type->value, $request->taskTypes()))
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
                        'result' => TaskResult::failed(TaskFailure::of($e->failureClass, 'setup')),
                    ]);
                    $task->transitionTo(DaemonTaskStatus::Failed);

                    continue;
                }

                event(new DaemonTaskLeased($task->id, $server->id));
                $this->broadcastBuildServer($task);
            }

            return $leased;
        });
    }

    private function broadcastBuildServer(DaemonTask $task): void
    {
        if ($task->type !== DaemonTaskType::Build || $task->deployment_id === null) {
            return;
        }

        $deployment = Deployment::query()->where('team_id', $task->team_id)->whereKey($task->deployment_id)
            ->with(['environment:id,public_id', 'serviceInstance:id,service_id', 'serviceInstance.service:id,public_id'])->first();
        if ($deployment === null) {
            return;
        }

        $event = new DeploymentUpdated($deployment->public_id, $deployment->environment->public_id, $deployment->serviceInstance->service->public_id, $deployment->status->value);
        DB::afterCommit(static function () use ($event): void {
            broadcast($event);
        });
    }
}
