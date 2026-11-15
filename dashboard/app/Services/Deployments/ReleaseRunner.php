<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Deployments\StepOutcome;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Tasks\RunOnceTasks;
use App\Services\Tasks\TaskCancellation;

/**
 * Release and seed commands as `run_once` tasks (docs/03 §7, docs/04 §13):
 * a one-off container from the deployment's image on a runtime server, in
 * the environment's network, with the deployment's runtime env (sealed to
 * that server at lease time; `maskEnv` lists the secret-bearing keys the
 * daemon masks in the shipped logs). The release step runs before rollout; a
 * non-zero exit fails the deployment with `release_error` and the old version
 * keeps serving. advance() is idempotent with the deployment row-locked, like
 * BuildOrchestrator's. Seed commands run once, after an instance's first
 * deploy (non-blocking).
 *
 * Server choice: a server that already runs workloads of this environment
 * (its network and its databases' aliases are there), preferring the one
 * serving this instance; else any online runtime server, where the daemon
 * creates the environment network for the task. The task is pinned to it.
 */
final readonly class ReleaseRunner
{
    public function __construct(
        private TaskCancellation $cancellation,
        private RunOnceTasks $runOnce,
    ) {}

    /**
     * @param  list<string>  $command  argv, run inside the deployment's image
     */
    public function advance(Deployment $deployment, array $command): StepOutcome
    {
        if ($command === []) {
            return StepOutcome::succeeded('No release command.');
        }

        $task = DaemonTask::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', DaemonTaskType::RunOnce->value)
            ->where('purpose', DaemonTaskPurpose::Release->value)
            ->latest('id')
            ->first();

        if (! $task instanceof DaemonTask) {
            return $this->create($deployment, $command, DaemonTaskPurpose::Release) instanceof DaemonTask
                ? StepOutcome::pending()
                : StepOutcome::failed(DeploymentFailureClass::PlacementError, 'No online runtime server can run the release command.');
        }

        $exit = $task->result?->runOnce?->exitCode;

        return match ($task->status) {
            DaemonTaskStatus::Pending, DaemonTaskStatus::Leased => StepOutcome::pending(),
            DaemonTaskStatus::Succeeded => $exit === 0
                ? StepOutcome::succeeded('The release command finished.')
                : StepOutcome::failed(DeploymentFailureClass::ReleaseError, 'The release command exited with code '.(is_int($exit) ? $exit : '?').'; the previous version keeps serving.'),
            DaemonTaskStatus::Failed => StepOutcome::failed(DeploymentFailureClass::ReleaseError, 'The release command could not run: '.($task->error ?? 'unknown error').'.'),
            DaemonTaskStatus::Cancelled => StepOutcome::failed(DeploymentFailureClass::ReleaseError, 'The release command was cancelled.'),
        };
    }

    public function cancel(Deployment $deployment): void
    {
        $this->cancellation->cancelForDeployment($deployment->id, DaemonTaskPurpose::Release);
    }

    /**
     * @param  list<string>  $command  argv
     */
    public function seed(Deployment $deployment, array $command): void
    {
        if ($command === [] || $deployment->image === null) {
            return;
        }

        $seeded = DaemonTask::query()
            ->where('type', DaemonTaskType::RunOnce->value)
            ->where('purpose', DaemonTaskPurpose::Seed->value)
            ->whereIn('deployment_id', Deployment::query()->select('id')->where('service_instance_id', $deployment->service_instance_id))
            ->exists();

        if (! $seeded) {
            $this->create($deployment, $command, DaemonTaskPurpose::Seed);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function create(Deployment $deployment, array $command, DaemonTaskPurpose $purpose): ?DaemonTask
    {
        $deployment->loadMissing(['environment', 'serviceInstance.service', 'revision']);
        $instance = $deployment->serviceInstance;
        $spec = $deployment->revision?->spec->service($instance->service->public_id);
        $server = RunOnceTasks::runtimeServer($deployment->team_id, array_values(array_filter([
            ...Placement::query()->where('deployment_id', $instance->active_deployment_id ?? 0)->pluck('server_id')->all(),
            ...Placement::query()
                ->whereIn('service_instance_id', $deployment->environment->serviceInstances()->select('id'))
                ->orderBy('id')
                ->pluck('server_id')
                ->all(),
        ], is_int(...))));

        if (! $server instanceof Server || $spec === null) {
            return null;
        }

        return $this->runOnce->command($deployment, $spec, $command, $server, $purpose, $deployment->id);
    }
}
