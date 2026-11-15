<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RemoteAccess\FinishRemoteAccess;
use App\Actions\Security\AdvanceInvestigation;
use App\Actions\Security\FailInventory;
use App\Actions\Services\RecordPlanResult;
use App\Actions\Sessions\FinishSession;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\Previews\PreviewStatus;
use App\Events\DaemonTaskFinished;
use App\Jobs\AdvanceDeploymentJob;
use App\Jobs\Backups\SyncBackupTaskJob;
use App\Jobs\Dockerfiles\AdvanceDockerfileGenerationJob;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Jobs\Transfers\SyncTransferTaskJob;
use App\Jobs\Updates\AdvanceUpdatesJob;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Previews\Preview;
use App\Services\Builds\BuildOrchestrator;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * A task finished (succeeded, failed, cancelled): hand it to its owner,
 * chosen by its type and purpose (docs/03 §7). Owners record the outcome
 * on their own rows; workflows that wait for those rows listen to the
 * owners' events (BackupRunFinished, ImportRunFinished, …), never to tasks.
 */
final readonly class RouteFinishedDaemonTask
{
    public function __construct(
        private RecordPlanResult $recordPlan,
        private FinishSession $finishSession,
        private FinishRemoteAccess $finishAccess,
        private FailInventory $failInventory,
        private AdvanceInvestigation $advanceInvestigation,
    ) {}

    public function handle(DaemonTaskFinished $event): void
    {
        $task = DaemonTask::query()->find($event->taskId);

        if (! $task instanceof DaemonTask) {
            return;
        }

        $owner = match ($task->type) {
            DaemonTaskType::Build => $this->build(...),
            DaemonTaskType::Plan => $this->plan(...),
            DaemonTaskType::RunOnce => $this->runOnce(...),
            DaemonTaskType::Backup, DaemonTaskType::Restore, DaemonTaskType::BackupSnapshots,
            DaemonTaskType::BackupMaintenance, DaemonTaskType::VerifyRestore => static fn (DaemonTask $task): PendingDispatch => dispatch(new SyncBackupTaskJob($task->id)),
            DaemonTaskType::DbImport, DaemonTaskType::VolumeImport,
            DaemonTaskType::DbExport, DaemonTaskType::VolumeExport => static fn (DaemonTask $task): PendingDispatch => dispatch(new SyncTransferTaskJob($task->id)),
            DaemonTaskType::SelfUpdate => static fn (): PendingDispatch => dispatch(new AdvanceUpdatesJob),
            DaemonTaskType::Session => $this->finishSession->handle(...),
            DaemonTaskType::Access => $this->finishAccess->handle(...),
            DaemonTaskType::Inventory => $this->inventory(...),
            DaemonTaskType::InspectContainer => $this->advanceInvestigation->handle(...),
            DaemonTaskType::Logs => null,
        };

        if ($owner !== null) {
            $owner($task);
        }
    }

    private function inventory(DaemonTask $task): void
    {
        if ($task->status !== DaemonTaskStatus::Succeeded) {
            $this->failInventory->handle($task);
        }
    }

    /**
     * A deployment's build advances it and the worker/cron deployments
     * waiting for it; a verification build advances its Dockerfile
     * generation (docs/20 §5).
     */
    private function build(DaemonTask $task): void
    {
        if ($task->deployment_id === null) {
            $this->dockerfileGeneration($task);

            return;
        }

        dispatch(new AdvanceDeploymentJob($task->deployment_id));

        $deployment = Deployment::query()->with('serviceInstance')->find($task->deployment_id);

        if ($deployment instanceof Deployment) {
            foreach (BuildOrchestrator::waitingFor($deployment) as $id) {
                dispatch(new AdvanceDeploymentJob($id));
            }
        }
    }

    private function plan(DaemonTask $task): void
    {
        $this->recordPlan->handle($task);
        $this->dockerfileGeneration($task);
    }

    private function runOnce(DaemonTask $task): void
    {
        if ($task->purpose === DaemonTaskPurpose::DockerfileCheck) {
            $this->dockerfileGeneration($task);
        } elseif ($task->purpose === DaemonTaskPurpose::Sanitize) {
            $this->previews($task);
        } elseif ($task->deployment_id !== null) {
            dispatch(new AdvanceDeploymentJob($task->deployment_id));
        }
    }

    private function dockerfileGeneration(DaemonTask $task): void
    {
        $ids = DockerfileGeneration::query()
            ->where(static fn (Builder $query) => $query
                ->where('plan_task_id', $task->id)
                ->orWhere('build_task_id', $task->id)
                ->orWhere('run_task_id', $task->id))
            ->pluck('id');

        foreach ($ids as $id) {
            if (is_int($id)) {
                dispatch(new AdvanceDockerfileGenerationJob($id));
            }
        }
    }

    private function previews(DaemonTask $task): void
    {
        if (Preview::query()->where('team_id', $task->team_id)->where('status', PreviewStatus::Preparing->value)->exists()) {
            dispatch(new AdvancePreviewsJob($task->team_id));
        }
    }
}
