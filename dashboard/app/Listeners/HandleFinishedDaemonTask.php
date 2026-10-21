<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Services\RecordPlanResult;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Events\DaemonTaskFinished;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Service;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A task finished: advance its deployment (build, release command), wake
 * worker/cron deployments waiting for this app's build, and record plan
 * previews (docs/04 §6: orchestration is event-driven).
 */
final readonly class HandleFinishedDaemonTask
{
    public function __construct(
        private RecordPlanResult $recordPlan,
    ) {}

    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->find($event->taskId);

        if (! $task instanceof DaemonTask) {
            return;
        }

        if ($task->type === DaemonTaskType::Plan) {
            $this->recordPlan->handle($task);
        }

        if ($task->deployment_id === null) {
            return;
        }

        dispatch(new AdvanceDeploymentJob($task->deployment_id));

        if ($task->type !== DaemonTaskType::Build) {
            return;
        }

        $deployment = Deployment::query()->with('serviceInstance')->find($task->deployment_id);

        if (! $deployment instanceof Deployment) {
            return;
        }

        $waiting = Deployment::query()
            ->where('revision_id', $deployment->revision_id)
            ->where('status', DeploymentStatus::Building->value)
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereIn(
                'service_id',
                Service::query()->select('id')->where('parent_service_id', $deployment->serviceInstance->service_id),
            ))
            ->pluck('id');

        foreach ($waiting as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDeploymentJob($id));
            }
        }
    }
}
