<?php

declare(strict_types=1);

namespace App\Queries;

use App\Casts\ServiceSourceCast;
use App\Data\Specs\ConnectionSpec;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Specs\VolumeSpec;
use App\Enums\EnvironmentKind;
use App\Enums\PreviewStrategy;
use App\Enums\VariableType;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Models\Volume;
use App\Support\Variables\VariableTargeting;

/**
 * Snapshots an environment's current intent (docs/06 §4: "intent tables are
 * the draft") as an EnvironmentSpec. A fixed number of queries regardless of
 * the number of services (no N+1).
 */
final class CurrentEnvironmentSpec
{
    public function get(Environment $environment): EnvironmentSpec
    {
        $environment->loadMissing('project');
        $project = $environment->project;
        // The branch of a preview environment (branch-targeted variables).
        $branch = $environment->kind === EnvironmentKind::Preview ? $environment->pr_head_ref : null;

        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            ->with(['service', 'domains', 'volumes'])
            ->get();

        /** @var array<int, ServiceInstance> $byServiceId */
        $byServiceId = $instances->keyBy('service_id')->all();
        $serviceIds = $instances->pluck('service_id')->all();

        $variables = Variable::query()
            ->where('project_id', $project->id)
            ->with(['targets', 'usedBy:services.id', 'connection:id,public_id,to_service_id'])
            ->orderBy('id')
            ->get();

        $connections = Connection::query()
            ->where('project_id', $project->id)
            ->whereIn('from_service_id', $serviceIds)
            ->whereIn('to_service_id', $serviceIds)
            ->with(['from:id,public_id', 'to:id,public_id'])
            ->get()
            ->keyBy('id');

        $serviceVariables = [];
        $connectionVariables = [];
        $sharedVariables = [];
        $usages = [];

        foreach ($variables as $variable) {
            $spec = $this->variableSpec($variable, $environment, $branch);

            if ($variable->connection_id !== null) {
                // Connection-owned variables apply wherever the connection exists (docs/06 §3.2).
                if ($variable->service_id !== null && $connections->has($variable->connection_id)) {
                    $connectionVariables[$variable->service_id][$variable->public_id] = $this->connectionVariableSpec($variable);
                }

                continue;
            }

            if (! $spec instanceof VariableSpec) {
                continue;
            }

            if ($variable->service_id === null) {
                $sharedVariables[$variable->public_id] = $spec;

                foreach ($variable->usedBy as $service) {
                    $usages[$service->id][] = $variable->public_id;
                }
            } else {
                $serviceVariables[$variable->service_id][$variable->public_id] = $spec;
            }
        }

        $services = [];

        foreach ($byServiceId as $serviceId => $instance) {
            $service = $instance->service;
            $parent = $service->parent_service_id === null ? null : ($byServiceId[$service->parent_service_id] ?? null)?->service;

            $domains = [];

            foreach ($instance->domains->sortBy('hostname') as $domain) {
                /** @var Domain $domain */
                $domains[$domain->hostname] = new DomainSpec($domain->public_id, $domain->hostname, $domain->kind, $domain->redirect_to);
            }

            $volumes = [];

            foreach ($instance->volumes->sortBy('public_id') as $volume) {
                /** @var Volume $volume */
                $volumes[$volume->public_id] = new VolumeSpec($volume->public_id, $volume->mount_path);
            }

            $own = $serviceVariables[$serviceId] ?? [];
            $owned = $connectionVariables[$serviceId] ?? [];
            $shared = $usages[$serviceId] ?? [];
            ksort($own);
            ksort($owned);
            sort($shared);

            $services[$service->public_id] = new ServiceSpec(
                id: $service->public_id,
                instanceId: $instance->public_id,
                slug: $service->slug,
                name: $service->name,
                kind: $service->kind,
                parentId: $parent?->public_id,
                source: ServiceSourceCast::toArray($service->source),
                icon: $service->icon,
                enabled: $instance->enabled,
                branchOverride: $instance->branch_override,
                build: $instance->build,
                deploy: $instance->deploy,
                scaling: $instance->scaling,
                variables: $own,
                connectionVariables: $owned,
                sharedVariableIds: $shared,
                domains: $domains,
                volumes: $volumes,
            );
        }

        ksort($services);
        ksort($sharedVariables);

        $connectionSpecs = [];

        foreach ($connections as $connection) {
            /** @var Connection $connection */
            $connectionSpecs[$connection->public_id] = new ConnectionSpec($connection->public_id, $connection->from->public_id, $connection->to->public_id, $connection->mode, $connection->style);
        }

        ksort($connectionSpecs);

        // Previews: stateful services set to "share base instance" resolve to
        // the base environment's instance (docs/06 §5). Never for fork
        // previews (docs/09 R20): they get neither the base's credentials nor
        // its network.
        $sharedServices = [];

        if ($environment->kind === EnvironmentKind::Preview && $environment->base_environment_id !== null && ! $environment->secrets_withheld) {
            $basePublicId = Environment::query()->whereKey($environment->base_environment_id)->value('public_id');

            foreach ($byServiceId as $instance) {
                if (is_string($basePublicId) && $instance->preview_strategy === PreviewStrategy::Share && $instance->service->kind->isStateful()) {
                    $sharedServices[$instance->service->public_id] = $basePublicId;
                }
            }

            ksort($sharedServices);
        }

        return new EnvironmentSpec(
            environment: new EnvironmentMetaSpec(
                id: $environment->public_id,
                slug: $environment->slug,
                name: $environment->name,
                kind: $environment->kind,
                projectId: $project->public_id,
                projectSlug: $project->slug,
                triggers: $environment->triggers,
                branch: $branch,
            ),
            services: $services,
            connections: $connectionSpecs,
            sharedVariables: $sharedVariables,
            sharedServices: $sharedServices,
        );
    }

    private function variableSpec(Variable $variable, Environment $environment, ?string $branch): ?VariableSpec
    {
        $targets = array_values($variable->targets->map(static fn (VariableTarget $target): array => [
            'environment_id' => $target->environment_id,
            'all_previews' => $target->all_previews,
            'branch_pattern' => $target->branch_pattern,
        ])->all());

        $layer = VariableTargeting::layer($targets, $environment->id, $environment->kind, $branch);

        if ($layer === null) {
            return null;
        }

        // Fork previews get no secrets (docs/09 R20): every secret not created
        // for this very environment (its own generated database credentials)
        // resolves to an empty value.
        $withheld = $environment->secrets_withheld
            && $variable->type === VariableType::Secret
            && ! array_any($targets, static fn (array $target): bool => $target['environment_id'] === $environment->id);

        return $this->toSpec($variable, $layer, $withheld);
    }

    private function connectionVariableSpec(Variable $variable): VariableSpec
    {
        return $this->toSpec($variable, VariableSpec::LAYER_ENVIRONMENT);
    }

    private function toSpec(Variable $variable, string $layer, bool $withheld = false): VariableSpec
    {
        return new VariableSpec(
            id: $variable->public_id,
            key: $variable->key,
            type: $variable->type,
            version: $variable->version,
            exposure: $variable->exposure(),
            ciphertext: $variable->value,
            layer: $layer,
            generated: $variable->generated,
            references: $variable->references,
            connectionId: $variable->connection?->public_id,
            withheld: $withheld,
        );
    }
}
