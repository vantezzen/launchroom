<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'personal' => false,
        ];
    }

    /**
     * Add $user as a member with $role (and make it their current team if unset).
     */
    public function withMember(User $user, TeamRole $role = TeamRole::Owner): static
    {
        return $this->afterCreating(function (Team $team) use ($user, $role): void {
            Membership::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => $role]);

            if ($user->current_team_id === null) {
                $user->forceFill(['current_team_id' => $team->id])->save();
            }

            $user->forgetTeamRoles();
        });
    }
}
