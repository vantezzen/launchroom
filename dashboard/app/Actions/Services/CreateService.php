<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\DatabaseSource;
use App\Data\Services\DeploySettings;
use App\Data\Services\GitSource;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ImageSource;
use App\Data\Services\ServiceSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\PlanServiceJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Projects\ServiceProvisioner;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/**
 * Adds a service to a project: its identity plus an instance in $environment
 * (adding from a canvas: the service exists only there until it is promoted
 * to other environments, docs/06 §6), or in every production/persistent
 * environment when none is given. Staged until Deploy (docs/06 §4).
 * Databases get per-environment credentials and a volume; web services a
 * generated domain.
 */
final readonly class CreateService
{
    public function __construct(
        private ServiceProvisioner $provisioner,
        private DatabaseEngines $engines,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Project $project, CreateServiceData $data, ?User $user, AuditActor $actor, ?Environment $environment = null): Service
    {
        if ($environment instanceof Environment && $environment->project_id !== $project->id) {
            throw DomainRuleViolation::forbidden('That environment belongs to another project.');
        }

        return DB::transaction(function () use ($project, $data, $user, $actor, $environment): Service {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $parent = $this->parent($project, $data);
            $source = $this->source($data, $parent);

            $service = Service::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'slug' => $this->slug($project, $data),
                'name' => $data->name,
                'kind' => $data->kind,
                'parent_service_id' => $parent?->id,
                'source' => $source,
                'icon' => $data->icon,
            ]);

            $deploy = $data->kind === ServiceKind::Database
                ? null
                : new DeploySettings(port: $data->port, startCommand: $data->startCommand);

            if ($deploy instanceof DeploySettings && $data->kind->requiresParent()) {
                $deploy = new DeploySettings(startCommand: $deploy->startCommand, healthcheck: HealthcheckSettings::none());
            }

            $environments = $environment instanceof Environment
                ? [$environment]
                : $project->environments()->where('kind', '!=', EnvironmentKind::Preview->value)->orderBy('id')->get()->all();

            if ($parent instanceof Service && $environment instanceof Environment
                && ! ServiceInstance::query()->current()->where('service_id', $parent->id)->where('environment_id', $environment->id)->exists()) {
                throw DomainRuleViolation::on('parentServiceId', "{$parent->name} isn't in {$environment->name}. Add or promote it there first.");
            }

            foreach ($environments as $environment) {
                $environment->setRelation('project', $project);
                $this->provisioner->provision($service, $environment, deploy: $deploy, actor: $user);
            }

            if ($service->kind === ServiceKind::App) {
                // "We detected…" previews (docs/04 §4).
                foreach (ServiceInstance::query()->where('service_id', $service->id)->pluck('id') as $instanceId) {
                    if (is_int($instanceId)) {
                        dispatch(new PlanServiceJob($instanceId))->afterCommit();
                    }
                }
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceCreated,
                actor: $actor,
                teamId: $project->team_id,
                subjectType: 'service',
                subjectId: $service->public_id,
                meta: ['project' => $project->public_id, 'kind' => $service->kind->value],
            ));

            return $service;
        });
    }

    private function parent(Project $project, CreateServiceData $data): ?Service
    {
        if (! $data->kind->requiresParent()) {
            return null;
        }

        $parent = $data->parentServiceId === null ? null : $project->services()->where('public_id', $data->parentServiceId)->first();

        if ($parent === null || $parent->kind !== ServiceKind::App) {
            throw DomainRuleViolation::on('parentServiceId', 'Workers and crons run the image of an app service in the same project.');
        }

        return $parent;
    }

    private function source(CreateServiceData $data, ?Service $parent): ServiceSource
    {
        if ($parent instanceof Service) {
            return $parent->source;
        }

        $source = $data->serviceSource();

        $valid = match ($data->kind) {
            ServiceKind::App => $source instanceof GitSource,
            ServiceKind::Image => $source instanceof ImageSource,
            ServiceKind::Database => $source instanceof DatabaseSource,
            ServiceKind::Worker, ServiceKind::Cron => false,
        };

        if (! $valid || ! $source instanceof ServiceSource) {
            throw DomainRuleViolation::on('source', "A {$data->kind->value} service needs a ".match ($data->kind) {
                ServiceKind::App => 'git repository',
                ServiceKind::Image => 'image reference',
                default => 'database engine',
            }.' source.');
        }

        if ($source instanceof GitSource) {
            $source->assertUsable();
        }

        if ($source instanceof DatabaseSource && ! in_array($source->version, $this->engines->for($source->engine)->versions(), true)) {
            throw DomainRuleViolation::on('source', "{$source->engine->label()} {$source->version} is not available. Choose one of: ".implode(', ', $this->engines->for($source->engine)->versions()).'.');
        }

        return $source;
    }

    private function slug(Project $project, CreateServiceData $data): string
    {
        /** @var list<string> $taken */
        $taken = $project->services()->pluck('slug')->all();

        if ($data->slug !== null) {
            if (in_array($data->slug, $taken, true) || in_array($data->slug, Slug::RESERVED, true)) {
                throw DomainRuleViolation::on('slug', 'This name is already used in the project or reserved.');
            }

            return $data->slug;
        }

        return Slug::unique(Slug::from($data->name, $data->kind->value), [...$taken, ...Slug::RESERVED]);
    }
}
