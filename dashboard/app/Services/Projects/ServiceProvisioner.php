<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Data\Services\BuildSettings;
use App\Data\Services\DatabaseSource;
use App\Data\Services\DeploySettings;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScalingSettings;
use App\Drivers\DatabaseEngines\DatabaseEngineDriver;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DomainKind;
use App\Enums\PreviewStrategy;
use App\Enums\ServiceKind;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Domains\GeneratedDomains;
use App\Services\Variables\VariableStore;

/**
 * Creates a service's instance in one environment with everything it needs
 * there: settings (defaults per kind, or copied from a template instance),
 * the engine's volume and per-environment generated credentials for
 * databases (docs/06 §3.1: generated once per environment), and a generated
 * domain for app services and image services with a port (docs/05 §5). Callers own the
 * transaction and authorization.
 */
final readonly class ServiceProvisioner
{
    public function __construct(
        private DatabaseEngines $engines,
        private VariableStore $variables,
        private GeneratedDomains $domains,
    ) {}

    public function provision(Service $service, Environment $environment, ?ServiceInstance $template = null, ?DeploySettings $deploy = null, ?User $actor = null): ServiceInstance
    {
        $environment->loadMissing('project');
        $project = $environment->project;
        $source = $service->source;
        $database = $source instanceof DatabaseSource ? $source : null;
        $driver = $database instanceof DatabaseSource ? $this->engines->for($database->engine) : null;

        $deploy = $template->deploy ?? $deploy ?? match (true) {
            $driver instanceof DatabaseEngineDriver => new DeploySettings(
                port: $driver->port(),
                startCommand: $driver->args($database->version),
                healthcheck: $driver->health(),
            ),
            $service->kind->requiresParent() => new DeploySettings(healthcheck: HealthcheckSettings::none()),
            default => new DeploySettings,
        };

        $instance = ServiceInstance::query()->create([
            'team_id' => $service->team_id,
            'environment_id' => $environment->id,
            'service_id' => $service->id,
            'enabled' => $template->enabled ?? true,
            'branch_override' => $template?->branch_override,
            'build' => $template->build ?? new BuildSettings,
            'deploy' => $deploy,
            'scaling' => $template->scaling ?? new ScalingSettings,
            'preview_strategy' => $template->preview_strategy ?? ($service->kind->isStateful() ? PreviewStrategy::NewEmpty : null),
        ]);

        if ($driver instanceof DatabaseEngineDriver) {
            Volume::query()->create([
                'team_id' => $service->team_id,
                'service_instance_id' => $instance->id,
                'mount_path' => $driver->volumeMountPath($database->version),
            ]);

            $target = [['environment_id' => $environment->id, 'all_previews' => false, 'branch_pattern' => null]];

            foreach ($driver->variables() as $variable) {
                $this->variables->create($project, $service, $variable->key, $variable->value, $variable->type, $target, actor: $actor);
            }
        }

        // Built apps usually learn their port from the build (docs/04 §13), so
        // they always get a domain; image services only with a port.
        if ($service->kind->isWeb() && ($deploy->port !== null || $service->kind === ServiceKind::App)) {
            $hostname = $this->domains->hostnameFor($service, $environment, $project);

            if ($hostname !== null) {
                Domain::query()->create([
                    'team_id' => $service->team_id,
                    'service_instance_id' => $instance->id,
                    'hostname' => $hostname,
                    'kind' => DomainKind::Generated,
                ]);
            }
        }

        return $instance;
    }
}
