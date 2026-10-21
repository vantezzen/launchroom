<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Environment;
use App\Models\Service;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The editable (non-connection) variables of a service that explicitly
 * target an environment: what the raw `.env` editor and the import preview
 * work on. Keys are unique here (overlapping rows are refused on save).
 */
final class EnvironmentVariableRows
{
    /**
     * @return Collection<int, Variable>
     */
    public function get(Environment $environment, Service $service): Collection
    {
        return Variable::query()
            ->where('project_id', $environment->project_id)
            ->where('service_id', $service->id)
            ->whereNull('connection_id')
            ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))
            ->with('targets')
            ->orderBy('key')
            ->get();
    }
}
