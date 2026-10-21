<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Data\Api\ApiAbilityOptionData;
use App\Data\Api\ApiTeamOptionData;
use App\Data\Api\ApiTokenData;
use App\Enums\ApiAbility;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;

/**
 * What the token pages offer (docs/16 §1.3, §4): the user's teams with the
 * abilities their role there can use, and the user's own tokens.
 */
final class ApiTokenOptions
{
    /**
     * Token lifetimes offered (days, config/api.php).
     *
     * @return list<int>
     */
    public static function lifetimes(): array
    {
        $lifetimes = [];

        foreach ((array) config('api.token_lifetimes') as $days) {
            if (is_int($days)) {
                $lifetimes[] = $days;
            }
        }

        return $lifetimes;
    }

    /**
     * @return list<ApiTeamOptionData>
     */
    public function teams(User $user): array
    {
        $teams = $user->teams()->orderBy('name')->get();
        $options = [];

        foreach ($teams as $team) {
            $role = $user->roleIn($team);

            if (! $role instanceof TeamRole) {
                continue;
            }

            $options[] = new ApiTeamOptionData(
                id: $team->public_id,
                name: $team->name,
                role: $role,
                current: $team->id === $user->current_team_id,
                abilities: self::abilities($role),
            );
        }

        return $options;
    }

    /**
     * @return list<ApiAbilityOptionData>
     */
    public static function abilities(TeamRole $role): array
    {
        return array_map(static fn (ApiAbility $ability): ApiAbilityOptionData => new ApiAbilityOptionData(
            value: $ability,
            label: $ability->label(),
            description: $ability->description(),
            available: $role->can($ability->grants() ?? Permission::View),
            default: in_array($ability, ApiAbility::defaults(), true),
        ), ApiAbility::cases());
    }

    /**
     * @return list<ApiTokenData>
     */
    public function tokens(User $user): array
    {
        return array_values(ApiToken::query()
            ->ownedBy($user)
            ->whereIn('team_id', Team::query()->select('id')->whereIn('id', $user->teamIdsQuery()))
            ->with('team:id,public_id,name')
            ->orderByDesc('id')
            ->get()
            ->map(ApiTokenData::fromToken(...))
            ->all());
    }
}
