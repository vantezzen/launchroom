<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * One page of an environment's deployments, newest first, optionally of
 * one service (`GET /environments/{environment}/deployments`). Keyset
 * pagination by id inside the already scoped environment.
 */
final class EnvironmentDeployments
{
    /**
     * @return array{list<Deployment>, int|null} the page and the id to continue before
     */
    public function page(Environment $environment, ?Service $service, ?int $before, int $limit): array
    {
        $query = Deployment::query()
            ->where('environment_id', $environment->id)
            ->with(['serviceInstance.service', 'environment.project'])
            ->orderByDesc('id');

        if ($service instanceof Service) {
            $query->whereHas('serviceInstance', static fn (Builder $instance) => $instance->where('service_id', $service->id));
        }

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = array_values($rows->take($limit)->all());
        $last = $page === [] ? null : $page[count($page) - 1];

        return [$page, $rows->count() > $limit && $last !== null ? $last->id : null];
    }
}
