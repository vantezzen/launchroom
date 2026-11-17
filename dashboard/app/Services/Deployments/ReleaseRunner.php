<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Deployments\StepOutcome;
use App\Data\Protocol\V1\Tasks\RunOnceMount;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Volume;
use App\Scheduling\PlacementFailed;
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
 *
 * A release command of a service with volumes runs where they are, with
 * them mounted at the service's paths (G62: migrations of an SQLite app
 * write to its volume, not to a throwaway container). On a first deploy the
 * scheduler picks that server now (the volumes are pinned to it, so the
 * rollout lands there too). Without an online server that announced
 * `releaseMounts`, the command runs as before, without them. Shared
 * volumes of other services (D73) are not mounted.
 */
final readonly class ReleaseRunner
{
    public function __construct(
        private TaskCancellation $cancellation,
        private RunOnceTasks $runOnce,
        private Placer $placer,
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
            return $this->create($deployment, $command, DaemonTaskPurpose::Release, mountVolumes: true) instanceof DaemonTask
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
    private function create(Deployment $deployment, array $command, DaemonTaskPurpose $purpose, bool $mountVolumes = false): ?DaemonTask
    {
        $deployment->loadMissing(['environment', 'serviceInstance.service', 'revision']);
        $instance = $deployment->serviceInstance;
        $spec = $deployment->revision?->spec->service($instance->service->public_id);

        if ($mountVolumes && $spec !== null) {
            [$server, $mounts] = $this->withVolumes($deployment, $spec);

            if ($server instanceof Server && $mounts !== []) {
                $task = $this->runOnce->command($deployment, $spec, $command, $server, $purpose, $deployment->id, $mounts);
                // Its NodeState declares the volumes (NodeStateContent::releaseVolumes).
                NodeStateInvalidated::servers([$server->id]);

                return $task;
            }
        }

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

    /**
     * The server holding the service's own volumes and their mounts, when it
     * is online, runs workloads and announced `releaseMounts`; else
     * [null, []].
     *
     * @return array{0: Server|null, 1: list<RunOnceMount>}
     */
    private function withVolumes(Deployment $deployment, ServiceSpec $spec): array
    {
        $instance = $deployment->serviceInstance;
        $volumes = $instance->volumes()->orderBy('id')->get();

        if ($volumes->isEmpty()) {
            return [null, []];
        }

        if ($volumes->every(static fn (Volume $volume): bool => $volume->server_id === null)) {
            try {
                $this->placer->plan($deployment, $spec, 1);
            } catch (PlacementFailed) {
                // The rollout fails with the reason; the command runs without its volumes.
                return [null, []];
            }

            $volumes = $instance->volumes()->orderBy('id')->get();
        }

        $serverId = $volumes->first(static fn (Volume $volume): bool => $volume->server_id !== null)?->server_id;
        $server = $serverId === null ? null : Server::query()->where('team_id', $deployment->team_id)->whereKey($serverId)->first();

        if (! $server instanceof Server || $server->status !== ServerStatus::Online || ! $server->hasRole(ServerRole::Runtime) || ! $server->supportsDaemonFeature(DaemonFeature::ReleaseMounts)
            // The volume is declared before any workload mounts it (FeatureGate).
            || ! $server->supportsDaemonFeature(DaemonFeature::PreparedVolumes)) {
            return [null, []];
        }

        $mounts = [];

        foreach ($volumes as $volume) {
            if ($volume->server_id === $server->id) {
                $mounts[] = new RunOnceMount($volume->public_id, $spec->volumes[$volume->public_id]->mountPath ?? $volume->mount_path);
            }
        }

        return [$server, $mounts];
    }
}
