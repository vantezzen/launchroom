<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Models\Domain;
use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The hostname a project is known by: the first custom domain of its
 * production services, else the first generated one (the project card's
 * address, ProjectSummaries). Redirecting domains don't count.
 */
final class ProjectPrimaryDomain
{
    public function for(Project $project): ?string
    {
        $domain = Domain::query()
            ->whereIn('kind', [DomainKind::Custom->value, DomainKind::Generated->value])
            ->whereNull('redirect_to')
            ->whereHas('serviceInstance', static fn (Builder $instance) => $instance
                ->whereNull('removed_at')
                ->whereHas('environment', static fn (Builder $environment) => $environment
                    ->where('project_id', $project->id)
                    ->where('kind', EnvironmentKind::Production->value)))
            ->orderByRaw('case when kind = ? then 0 else 1 end', [DomainKind::Custom->value])
            ->orderBy('id')
            ->first(['hostname']);

        return $domain?->hostname;
    }
}
