<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Data\Protocol\V1\Tasks\BuildSpec;
use App\Data\Protocol\V1\Tasks\RunOnceProbe;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ServiceSpec;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\Server;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Tasks\RunOnceTasks;
use InvalidArgumentException;

/**
 * Starts a verified image once (docs/20 §5 step 4): a `run_once` with a
 * `probe`, pinned to an online runtime server (the build server when it has
 * the runtime role, so no pull), on the environment's network, with the
 * service's limits.
 */
final readonly class VerificationRuns
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private RunOnceTasks $runOnce,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function create(DockerfileGeneration $generation, string $image, DaemonTask $build): DaemonTask
    {
        $instance = $generation->serviceInstance;
        $environment = $instance->environment;
        $server = RunOnceTasks::runtimeServer($generation->team_id, $build->server_id === null ? [] : [$build->server_id]);

        if (! $server instanceof Server) {
            throw DomainRuleViolation::on('generation', 'No server that runs apps is online to start the image.');
        }

        try {
            $buildSpec = $build->taskSpec();
        } catch (InvalidArgumentException) {
            $buildSpec = null;
        }

        $deployment = $buildSpec instanceof BuildSpec ? $buildSpec->inputs->deployment : null;

        if ($deployment === null) {
            throw DomainRuleViolation::on('generation', 'The verification build has no id.');
        }

        $spec = $this->currentSpec->get($environment)->service($generation->service->public_id);
        $probeSeconds = config()->integer('launchroom.dockerfile_generation.probe_seconds');
        $healthPath = $generation->detected?->healthPath;

        return $this->runOnce->startCheck(
            $generation->team_id,
            $environment,
            $generation->service->public_id,
            $deployment,
            $image,
            $spec instanceof ServiceSpec ? $spec->scaling : new ScalingSettings,
            $server,
            new RunOnceProbe($generation->port ?? 8080, $probeSeconds.'s', $healthPath),
            $probeSeconds,
        );
    }
}
