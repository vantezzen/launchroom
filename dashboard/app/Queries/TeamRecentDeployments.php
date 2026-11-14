<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\DeploymentSummaryData;
use App\Data\Deployments\RecentDeploymentData;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Team;

/**
 * The team's newest deployments across its projects, for "Recent
 * deployments" on the projects page (docs/08 §13).
 */
final readonly class TeamRecentDeployments
{
    public function __construct(private DeploymentSummaries $summaries) {}

    /**
     * @return list<RecentDeploymentData>
     */
    public function for(Team $team, int $limit): array
    {
        $rows = $this->summaries->rows(
            DeploymentSummaries::withRelations(Deployment::query()->where('team_id', $team->id))
                ->latest('id')
                ->limit($limit)
                ->get(),
        );

        if ($rows === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Project::query()
            ->where('team_id', $team->id)
            ->whereIn('public_id', array_values(array_unique(array_map(static fn (DeploymentSummaryData $row): string => $row->projectId, $rows))))
            ->pluck('name', 'public_id')
            ->all();

        return array_map(static fn (DeploymentSummaryData $row): RecentDeploymentData => new RecentDeploymentData($row, $names[$row->projectId] ?? ''), $rows);
    }
}
