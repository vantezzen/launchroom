<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Server;
use App\Queries\CurrentEnvironmentSpec;

/**
 * Starts a verified image once (docs/20 §5 step 4): a `run_once` with a
 * `probe`, pinned to an online runtime server (the build server when it has
 * the runtime role, so no pull), on the environment's network, with the
 * service's limits and only `PORT` in its env (no variables: nothing secret
 * reaches a Dockerfile nobody reviewed yet).
 */
final readonly class VerificationRuns
{
    public const string PURPOSE = 'dockerfile_check';

    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function create(DockerfileGeneration $generation, string $image, DaemonTask $build): DaemonTask
    {
        $instance = $generation->serviceInstance;
        $environment = $instance->environment;
        $server = $this->server($generation->team_id, $build->server_id);

        if (! $server instanceof Server) {
            throw DomainRuleViolation::on('generation', 'No server that runs apps is online to start the image.');
        }

        $spec = $this->currentSpec->get($environment)->service($generation->service->public_id);
        $scaling = $spec instanceof ServiceSpec ? $spec->scaling : new ScalingSettings;
        $port = $generation->port ?? 8080;
        $deployment = $build->spec->deployment ?? null;
        $probeSeconds = config()->integer('launchroom.dockerfile_generation.probe_seconds');
        $healthPath = is_string($generation->detected['healthPath'] ?? null) ? $generation->detected['healthPath'] : null;

        if (! is_string($deployment)) {
            throw DomainRuleViolation::on('generation', 'The verification build has no id.');
        }

        return DaemonTask::query()->create([
            'team_id' => $generation->team_id,
            'server_id' => $server->id,
            'pinned_to_server' => true,
            'required_role' => ServerRole::Runtime,
            'type' => DaemonTaskType::RunOnce,
            'purpose' => self::PURPOSE,
            'spec' => json_decode(json_encode([
                'service' => $generation->service->public_id,
                'environment' => $environment->public_id,
                'deployment' => $deployment,
                'image' => $image,
                'network' => ['id' => $environment->networkId(), 'name' => $environment->networkName(), 'subnet' => $environment->network_subnet],
                'timeout' => ($probeSeconds + 60).'s',
                'resources' => [
                    'cpus' => $scaling->cpus,
                    'memoryMb' => $scaling->memoryMb,
                    'pids' => $scaling->pids(),
                ],
                'probe' => [
                    'port' => $port,
                    ...($healthPath === null ? [] : ['path' => $healthPath]),
                    'within' => $probeSeconds.'s',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR),
            'seal' => ['env' => ['values' => ['PORT' => (string) $port]]],
            'max_attempts' => 1,
            'timeout_seconds' => $probeSeconds + 180,
        ]);
    }

    private function server(int $teamId, ?int $buildServerId): ?Server
    {
        $online = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Runtime->value)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $server = $buildServerId === null ? null : $online->get($buildServerId);

        return $server instanceof Server ? $server : $online->first();
    }
}
