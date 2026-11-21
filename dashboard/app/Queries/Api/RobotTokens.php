<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Data\Api\RobotScopeOptionData;
use App\Data\Api\RobotScopeServiceData;
use App\Data\Api\RobotTokenData;
use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;

/**
 * Settings → Robot tokens (docs/16 §1.4): the team's robot tokens and the
 * projects and services a new one can be scoped to.
 */
final class RobotTokens
{
    /**
     * @return list<RobotTokenData>
     */
    public function forTeam(Team $team): array
    {
        $robots = User::query()->where('robot_team_id', $team->id)->with('robotCreator:id,name')->get()->keyBy('id');

        if ($robots->isEmpty()) {
            return [];
        }

        return array_values(ApiToken::query()
            ->where('team_id', $team->id)
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $robots->keys()->all())
            ->with(['project:id,public_id,name', 'service:id,public_id,name'])
            ->orderBy('name')
            ->orderByDesc('id')
            ->get()
            ->map(static function (ApiToken $token) use ($robots): RobotTokenData {
                /** @var User $robot */
                $robot = $robots->get($token->tokenable_id);

                return RobotTokenData::fromToken($token, $robot);
            })
            ->all());
    }

    /**
     * @return list<RobotScopeOptionData>
     */
    public function scopes(Team $team): array
    {
        /** @var array<int, list<RobotScopeServiceData>> $services */
        $services = [];

        foreach (Service::query()
            ->whereIn('project_id', Project::query()->select('id')->where('team_id', $team->id))
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'project_id']) as $service) {
            $services[$service->project_id][] = new RobotScopeServiceData($service->public_id, $service->name);
        }

        return array_values(Project::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'public_id', 'name'])
            ->map(static fn (Project $project): RobotScopeOptionData => new RobotScopeOptionData(
                id: $project->public_id,
                name: $project->name,
                services: $services[$project->id] ?? [],
            ))
            ->all());
    }
}
