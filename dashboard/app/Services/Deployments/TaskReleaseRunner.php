<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Contracts\ReleaseRunner;
use App\Data\Deployments\StepOutcome;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Tasks\TaskCancellation;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Variables\SecretTaint;

/**
 * Release and seed commands as `run_once` tasks (docs/03 §7, docs/04 §13):
 * a one-off container from the deployment's image on a runtime server, in
 * the environment's network, with the deployment's runtime env (sealed to
 * that server at lease time; `maskEnv` lists the secret-bearing keys the
 * daemon masks in the shipped logs). The release step runs before rollout; a
 * non-zero exit fails the deployment with `release_error` and the old version
 * keeps serving. Seed commands run once, after an instance's first deploy.
 *
 * Server choice: a server that already runs workloads of this environment
 * (its network and its databases' aliases are there), preferring the one
 * serving this instance; else any online runtime server, where the daemon
 * creates the environment network for the task. The task is pinned to it.
 */
final readonly class TaskReleaseRunner implements ReleaseRunner
{
    public const string PURPOSE_RELEASE = 'release';

    public const string PURPOSE_SEED = 'seed';

    public function __construct(
        private TaskCancellation $cancellation,
    ) {}

    public function advance(Deployment $deployment, array $command): StepOutcome
    {
        if ($command === []) {
            return StepOutcome::succeeded('No release command.');
        }

        $task = DaemonTask::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', DaemonTaskType::RunOnce->value)
            ->where('purpose', self::PURPOSE_RELEASE)
            ->latest('id')
            ->first();

        if (! $task instanceof DaemonTask) {
            return $this->create($deployment, $command, self::PURPOSE_RELEASE) instanceof DaemonTask
                ? StepOutcome::pending()
                : StepOutcome::failed(DeploymentFailureClass::PlacementError, 'No online runtime server can run the release command.');
        }

        $runOnce = is_array($task->result['runOnce'] ?? null) ? $task->result['runOnce'] : [];
        $exit = $runOnce['exitCode'] ?? null;

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
        $this->cancellation->cancelForDeployment($deployment->id, self::PURPOSE_RELEASE);
    }

    public function seed(Deployment $deployment, array $command): void
    {
        if ($command === [] || $deployment->image === null) {
            return;
        }

        $seeded = DaemonTask::query()
            ->where('type', DaemonTaskType::RunOnce->value)
            ->where('purpose', self::PURPOSE_SEED)
            ->whereIn('deployment_id', Deployment::query()->select('id')->where('service_instance_id', $deployment->service_instance_id))
            ->exists();

        if (! $seeded) {
            $this->create($deployment, $command, self::PURPOSE_SEED);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function create(Deployment $deployment, array $command, string $purpose): ?DaemonTask
    {
        $deployment->loadMissing(['environment', 'serviceInstance.service', 'revision']);
        $server = $this->server($deployment);
        $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);

        if (! $server instanceof Server || $spec === null || $deployment->image === null) {
            return null;
        }

        $environment = $deployment->environment;
        $timeout = config()->integer('launchroom.deployments.release_timeout_seconds');
        // Secrets and config values embedding one are masked in the shipped
        // logs (docs/09 R16): release output (migration errors) often prints DSNs.
        $mask = SecretTaint::fromSpec($deployment->revision->spec)->taintedKeys($spec->slug);

        return DaemonTask::query()->create([
            'team_id' => $deployment->team_id,
            'server_id' => $server->id,
            'pinned_to_server' => true,
            'required_role' => ServerRole::Runtime,
            'type' => DaemonTaskType::RunOnce,
            'purpose' => $purpose,
            'spec' => json_decode(json_encode([
                'service' => $deployment->serviceInstance->service->public_id,
                'environment' => $environment->public_id,
                'deployment' => $deployment->public_id,
                'image' => $deployment->image,
                ...array_filter(EffectiveDeploy::for($spec, $deployment)->argv($command), static fn (?array $argv): bool => $argv !== null),
                ...($mask === [] ? [] : ['maskEnv' => array_slice($mask, 0, 1024)]),
                'network' => ['id' => $environment->networkId(), 'name' => $environment->networkName(), 'subnet' => $environment->network_subnet],
                'timeout' => $timeout.'s',
                'resources' => ['cpus' => $spec->scaling->cpus, 'memoryMb' => $spec->scaling->memoryMb, 'pids' => $spec->scaling->pids()],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR),
            // The full runtime env (plus build-summary defaults), sealed at lease time.
            'seal' => ['env' => ['deployment' => $deployment->id, 'keys' => null, 'defaults' => true]],
            'max_attempts' => 1,
            'timeout_seconds' => $timeout + 120,
            'deployment_id' => $deployment->id,
        ]);
    }

    private function server(Deployment $deployment): ?Server
    {
        $online = Server::query()
            ->where('team_id', $deployment->team_id)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Runtime->value)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $instance = $deployment->serviceInstance;
        $candidates = [
            ...Placement::query()->where('deployment_id', $instance->active_deployment_id ?? 0)->pluck('server_id')->all(),
            ...Placement::query()
                ->whereIn('service_instance_id', $deployment->environment->serviceInstances()->select('id'))
                ->orderBy('id')
                ->pluck('server_id')
                ->all(),
            ...$online->keys()->all(),
        ];

        foreach ($candidates as $id) {
            $server = is_int($id) ? $online->get($id) : null;

            if ($server instanceof Server) {
                return $server;
            }
        }

        return null;
    }
}
