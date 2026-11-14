<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Data\Security\SecurityScopeData;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Metrics\Cast;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The Security page narrowed to a project or a service (docs/22 §9.2):
 * the service instances it covers, resolved inside the team. Ids that
 * don't match their grammar or aren't the team's give no scope (`null`
 * from `resolve`), so a foreign id never widens or reveals anything.
 */
final readonly class SecurityScope
{
    /**
     * @param  list<int>  $instances
     */
    public function __construct(
        public SecurityScopeData $data,
        public array $instances,
    ) {}

    public static function resolve(Team $team, mixed $project, mixed $service): ?self
    {
        if (is_string($service) && preg_match('/^svc_[0-9A-HJKMNP-TV-Z]{26}$/D', $service) === 1) {
            $model = Service::query()->where('team_id', $team->id)->where('public_id', $service)->with('project:id,name')->first();

            return $model instanceof Service
                ? new self(new SecurityScopeData('service', $model->public_id, $model->name, $model->project->name), self::instances($team, 'service_id', $model->id))
                : null;
        }

        if (is_string($project) && preg_match('/^prj_[0-9A-HJKMNP-TV-Z]{26}$/D', $project) === 1) {
            $model = Project::query()->where('team_id', $team->id)->where('public_id', $project)->first();

            return $model instanceof Project
                ? new self(new SecurityScopeData('project', $model->public_id, $model->name), self::instances($team, 'project_id', $model->id))
                : null;
        }

        return null;
    }

    /**
     * Whether a scope was asked for (so an unknown one can be refused).
     */
    public static function requested(mixed $project, mixed $service): bool
    {
        return (is_string($service) && $service !== '') || (is_string($project) && $project !== '');
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $byInstance
     * @return array<int, T>
     */
    public function filter(array $byInstance): array
    {
        return array_intersect_key($byInstance, array_flip($this->instances));
    }

    /**
     * @return list<int>
     */
    private static function instances(Team $team, string $column, int $id): array
    {
        return array_values(array_map(Cast::id(...), ServiceInstance::query()
            ->where('team_id', $team->id)
            ->when($column === 'project_id', static fn ($q) => $q->whereHas('environment', static fn (Builder $e) => $e->where('project_id', $id)), static fn ($q) => $q->where('service_id', $id))
            ->pluck('id')
            ->all()));
    }
}
