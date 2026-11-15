<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\Resources;
use App\Data\Protocol\V1\Tasks\RunOnceProbe;
use App\Data\Protocol\V1\Tasks\RunOnceSpec;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Data\Tasks\EnvSeal;
use App\Data\Tasks\TaskSeal;
use App\Enums\DaemonTaskPurpose;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Server;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Deployments\EffectiveDeploy;

/**
 * `run_once` tasks (docs/03 §7): a one-off container from a deployment's
 * image in its environment's network, with the service's limits, pinned to
 * one runtime server. launchroomd creates the network when it doesn't exist
 * there yet.
 */
final readonly class RunOnceTasks
{
    public function __construct(
        private DaemonTasks $tasks,
        private SpecVariableResolver $resolver,
    ) {}

    /**
     * A command in a deployment's image with its runtime env (the full
     * snapshot plus the build summary's defaults, sealed at lease time): the
     * release and seed commands (docs/04 §13), the sanitize command of a
     * preview clone (docs/06 §5). Secrets and config values embedding one
     * are masked in the shipped logs (docs/09 R16): such output often prints
     * DSNs.
     *
     * @param  list<string>  $command
     * @param  int|null  $deploymentId  the deployment the task belongs to (advanced when it finishes)
     */
    public function command(Deployment $deployment, ServiceSpec $spec, array $command, Server $server, DaemonTaskPurpose $purpose, ?int $deploymentId): ?DaemonTask
    {
        $deployment->loadMissing(['environment.project', 'serviceInstance.service', 'revision']);

        if ($deployment->image === null || $deployment->revision === null) {
            return null;
        }

        $timeout = config()->integer('launchroom.deployments.release_timeout_seconds');
        $mask = $this->resolver->taint($deployment->environment->project, $deployment->revision->spec)->taintedKeys($spec->slug);
        $argv = EffectiveDeploy::for($spec, $deployment)->argv($command);

        return $this->tasks->create(
            $deployment->team_id,
            new RunOnceSpec(
                service: $deployment->serviceInstance->service->public_id,
                environment: $deployment->environment->public_id,
                deployment: $deployment->public_id,
                image: $deployment->image,
                network: NetworkSpec::of($deployment->environment),
                timeout: $timeout.'s',
                resources: Resources::of($spec->scaling),
                command: $argv['command'],
                args: $argv['args'],
                maskEnv: $mask === [] ? null : array_slice($mask, 0, 1024),
            ),
            $timeout + 120,
            pin: $server,
            role: ServerRole::Runtime,
            seal: new TaskSeal(env: EnvSeal::deployment($deployment->id, defaults: true)),
            purpose: $purpose,
            deploymentId: $deploymentId,
        );
    }

    /**
     * Starts an image once and checks from outside that it listens (docs/20
     * §5): only `PORT` in its env, so nothing secret reaches a Dockerfile
     * nobody reviewed yet.
     */
    public function startCheck(int $teamId, Environment $environment, string $serviceId, string $deploymentId, string $image, ScalingSettings $scaling, Server $server, RunOnceProbe $probe, int $probeSeconds): DaemonTask
    {
        return $this->tasks->create(
            $teamId,
            new RunOnceSpec(
                service: $serviceId,
                environment: $environment->public_id,
                deployment: $deploymentId,
                image: $image,
                network: NetworkSpec::of($environment),
                timeout: ($probeSeconds + 60).'s',
                resources: Resources::of($scaling),
                probe: $probe,
            ),
            $probeSeconds + 180,
            pin: $server,
            role: ServerRole::Runtime,
            seal: new TaskSeal(env: EnvSeal::values(['PORT' => (string) $probe->port])),
            purpose: DaemonTaskPurpose::DockerfileCheck,
        );
    }

    /**
     * @param  list<int>  $preferred
     */
    public static function runtimeServer(int $teamId, array $preferred = []): ?Server
    {
        $online = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Runtime->value)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($preferred as $id) {
            $server = $online->get($id);

            if ($server instanceof Server) {
                return $server;
            }
        }

        $first = $online->first();

        return $first instanceof Server ? $first : null;
    }
}
