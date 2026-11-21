<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\NodeStatusData;
use App\Data\Canvas\ProjectStatusData;
use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Support\Canvas\NodeStatus;

/**
 * Each project's status for the project switcher and ⌘K (docs/08 §8): the
 * aggregate NodeStatus of its production environment, or of the
 * environment being looked at for that environment's project.
 */
final readonly class ProjectStatuses
{
    public function __construct(private ServiceStatusIndex $statuses) {}

    /**
     * The environment whose statuses each project shows.
     *
     * @param  iterable<Environment>  $environments
     * @return array<int, Environment> project id => environment
     */
    public static function shown(iterable $environments, ?Environment $context): array
    {
        $shown = [];

        foreach ($environments as $environment) {
            if ($environment->kind === EnvironmentKind::Production) {
                $shown[$environment->project_id] ??= $environment;
            }
        }

        if ($context instanceof Environment) {
            $shown[$context->project_id] = $context;
        }

        return $shown;
    }

    /**
     * @param  array<int, array{status: NodeStatusData}>  $entries  service id => ServiceStatusIndex entry
     */
    public static function aggregate(array $entries): NodeStatusData
    {
        return NodeStatus::aggregate(array_values(array_map(static fn (array $entry): NodeStatusData => $entry['status'], $entries)));
    }

    /**
     * Every project of the team with a production environment (or the context).
     *
     * @return list<ProjectStatusData>
     */
    public function forTeam(int $teamId, ?Environment $context): array
    {
        $environments = Environment::query()
            ->where('team_id', $teamId)
            ->where('kind', EnvironmentKind::Production->value)
            ->with('project:id,public_id')
            ->get(['id', 'public_id', 'project_id', 'kind', 'team_id']);

        if ($context instanceof Environment) {
            $context->loadMissing('project:id,public_id');
        }

        $shown = self::shown($environments, $context);
        $index = $this->statuses->in(array_values(array_map(static fn (Environment $environment): int => $environment->id, $shown)));
        $out = [];

        foreach ($shown as $environment) {
            $out[] = new ProjectStatusData($environment->project->public_id, self::aggregate($index[$environment->id] ?? []));
        }

        return $out;
    }
}
