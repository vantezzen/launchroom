<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Canvas\DetectedPlanData;
use App\Data\Security\SecurityServiceRefData;
use App\Models\ServiceInstance;
use App\Support\Canvas\ServiceGlyph;

/**
 * Service instances of a team as references for the Security page (name,
 * logo, project, environment, panel link), loaded once per request.
 */
final class SecurityServiceRefs
{
    /** @var array<int, SecurityServiceRefData> */
    private array $refs = [];

    /** @var array<int, ServiceInstance> */
    private array $instances = [];

    /**
     * @param  list<int>  $ids
     */
    public function load(int $teamId, array $ids): void
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($this->refs)));

        if ($missing === []) {
            return;
        }

        foreach (ServiceInstance::query()->where('team_id', $teamId)->whereKey($missing)->with(['service', 'environment.project', 'activeDeployment'])->get() as $instance) {
            $this->instances[$instance->id] = $instance;
            $this->refs[$instance->id] = self::ref($instance);
        }
    }

    public function get(int $id): ?SecurityServiceRefData
    {
        return $this->refs[$id] ?? null;
    }

    public function instance(int $id): ?ServiceInstance
    {
        return $this->instances[$id] ?? null;
    }

    public static function ref(ServiceInstance $instance): SecurityServiceRefData
    {
        $service = $instance->service;
        $environment = $instance->environment;
        $project = $environment->project;
        $summary = $instance->activeDeployment?->plan['summary'] ?? ($instance->plan['summary'] ?? null);
        $framework = is_array($summary) && is_string($summary['framework'] ?? null) && $summary['framework'] !== '' ? DetectedPlanData::frameworkLabel($summary['framework']) : null;

        return new SecurityServiceRefData(
            id: $service->public_id,
            name: $service->name,
            logo: ServiceGlyph::logo($service, $framework),
            kind: $service->kind->value,
            projectId: $project->public_id,
            projectName: $project->name,
            environmentId: $environment->public_id,
            environmentName: $environment->name,
            href: "/p/{$project->public_id}/{$environment->public_id}/s/{$service->public_id}",
        );
    }
}
